<?php

namespace CyberSource\Shopware6\Controllers;

use GuzzleHttp\Client;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Order\OrderEntity;
use CyberSource\Shopware6\Mappers\OrderMapper;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use CyberSource\Shopware6\Exceptions\APIException;
use Shopware\Core\Checkout\Payment\PaymentException;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use CyberSource\Shopware6\Mappers\OrderClientReferenceMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use CyberSource\Shopware6\Exceptions\OrderRefundPaymentStateException;
use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Psr\Log\LoggerInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
class CyberSourceController extends AbstractController
{
    private EntityRepository $orderTransactionRepository;
    private EntityRepository $orderRepository;
    private ConfigurationService $configurationService;
    private CyberSourceFactory $cyberSourceFactory;
    private OrderTransactionStateHandler $orderTransactionStateHandler;
    private TranslatorInterface $translator;
    private CartService $cartService;
    private OrderService $orderService;
    private StateMachineRegistry $stateMachineRegistry;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $orderTransactionRepository,
        EntityRepository $orderRepository,
        ConfigurationService $configurationService,
        CyberSourceFactory $cyberSourceFactory,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        TranslatorInterface $translator,
        CartService $cartService,
        OrderService $orderService,
        StateMachineRegistry $stateMachineRegistry,
        LoggerInterface $logger
    ) {
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->configurationService = $configurationService;
        $this->cyberSourceFactory = $cyberSourceFactory;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->translator = $translator;
        $this->cartService = $cartService;
        $this->orderService = $orderService;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->logger = $logger;
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}",
        name: "api.cybersource.shopware_order_transaction_details",
        methods: ["GET"],
        defaults: ["_acl" => ["order.viewer"]]
    )]
    public function getShopwareOrderTransactionDetails(
        string $orderId,
        Context $context
    ) {
        $orderTransaction = $this->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }

        $paymentStatus = $this->getPaymentStatus($orderTransaction);
        $cybersourceTransactionId = $this->getCybersourcePaymentTransactionId(
            $orderTransaction,
            $paymentStatus
        );
        $orderData = $orderTransaction->getOrder();
        $response = [
            'cybersource_transaction_id' => $cybersourceTransactionId,
            'payment_status' => $paymentStatus,
            'amount' => $orderData->getAmountTotal()
        ];

        return new JsonResponse($response);
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}/capture/{cybersourceTransactionId}",
        name: "api.cybersource.order_capture_details",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function captureAuthorize(
        string $orderId,
        string $cybersourceTransactionId,
        Context $context
    ) {
        $orderTransaction = $this->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
        $orderEntity = $orderTransaction->getOrder();
        $currencyEntity = $orderEntity->getCurrency();
        $currency = $currencyEntity->getShortName();
        $totalOrderAmount = $orderEntity->getAmountTotal();
        $environmentUrl = $this->configurationService->getBaseUrl();
        $requestSignature = $this->configurationService->getSignatureContract();
        $shopwareOrderTransactionId = $orderTransaction->id;
        $lineItems = $orderEntity->getLineItems();

        $cyberSource = $this->cyberSourceFactory->createCyberSource(
            $environmentUrl,
            $requestSignature
        );
        $orderLineItemsData = $this->transformLineItems($lineItems);
        $clientReference = $this->getClientReference($orderEntity);
        $orderData = [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $totalOrderAmount,
                    'currency' => $currency
                ],
                'lineItems' => $orderLineItemsData
            ],
            'clientReferenceInformation' => $clientReference['clientReferenceInformation']
        ];

        try {
            $response = $cyberSource->capturePayment($cybersourceTransactionId, $orderData);
            $this->orderTransactionStateHandler->paid($shopwareOrderTransactionId, $context);
        } catch (\Exception $exception) {
            throw new APIException($cybersourceTransactionId, 'API_ERROR', $exception->getMessage());
        }

        return new JsonResponse($response);
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}/refund/{cybersourceTransactionId}",
        name: "api.cybersource.order_refund_payment",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function orderRefund(
        string $orderId,
        string $cybersourceTransactionId,
        Context $context,
        Request $request
    ) {
        $rawRequestBody = $request->getContent();
        if ($rawRequestBody === null || !json_validate($rawRequestBody)) {
            throw PaymentException::refundInterrupted(
                $cybersourceTransactionId,
                $this->translator->trans(
                    'cybersource_shopware6.exception.CYBERSOURCE_REFUND_AMOUNT_INCORRECT'
                )
            );
        }
        $requestBody = json_decode($rawRequestBody);
        if (!isset($requestBody->newTotalAmount)) {
            throw PaymentException::refundInterrupted(
                $cybersourceTransactionId,
                $this->translator->trans(
                    'cybersource_shopware6.exception.CYBERSOURCE_REFUND_AMOUNT_INCORRECT'
                )
            );
        }
        $newTotalAmount = (float) $requestBody->newTotalAmount;
        $requestLineItems = isset($requestBody->lineItems) ? $requestBody->lineItems : null;
        $orderTransaction = $this->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
        $orderEntity = $orderTransaction->getOrder();

        if (!$this->canRefund($orderTransaction)) {
            throw new OrderRefundPaymentStateException();
        }
        $totalOrderAmount = $orderEntity->getAmountTotal();
        if (!($newTotalAmount >= 0 && $newTotalAmount <= $totalOrderAmount)) {
            throw PaymentException::refundInterrupted(
                $cybersourceTransactionId,
                $this->translator->trans(
                    'cybersource_shopware6.exception.CYBERSOURCE_REFUND_AMOUNT_INCORRECT'
                )
            );
        }
        $currencyEntity = $orderEntity->getCurrency();
        $currency = $currencyEntity->getShortName();

        $environmentUrl = $this->configurationService->getBaseUrl();
        $requestSignature = $this->configurationService->getSignatureContract();
        $shopwareOrderTransactionId = $orderTransaction->id;

        $cyberSource = $this->cyberSourceFactory->createCyberSource(
            $environmentUrl,
            $requestSignature
        );
        if ($requestLineItems !== null) {
            $orderLineItemsData = json_decode(
                json_encode($requestLineItems),
                true
            );
        } else {
            $lineItems = $orderEntity->getLineItems()->getElements();
            $orderLineItemsData = $this->transformLineItems($lineItems);
        }

        $clientReference = $this->getClientReference($orderEntity);
        $refundAmount = $newTotalAmount < $totalOrderAmount ? $totalOrderAmount - $newTotalAmount : $totalOrderAmount;
        $refundPaymentRequestData = [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' =>  $refundAmount,
                    'currency' => $currency,
                ],
                'lineItems' => $orderLineItemsData
            ],
            'clientReferenceInformation' => $clientReference['clientReferenceInformation']
        ];

        try {
            $refundPaymentResponse = $cyberSource->refundPayment($cybersourceTransactionId, $refundPaymentRequestData);
            if ($refundAmount !== $totalOrderAmount) {
                $this->orderTransactionStateHandler->refundPartially($shopwareOrderTransactionId, $context);
            } else {
                $this->orderTransactionStateHandler->refund($shopwareOrderTransactionId, $context);
            }
            $paymentStatus = $this->getPaymentStatus($orderTransaction);
            $transactionId = $this->getCybersourcePaymentTransactionId($orderTransaction, $paymentStatus);
            $this->orderTransactionRepository->update([
                [
                    'id'           => $shopwareOrderTransactionId,
                    'customFields' => [
                        'cybersource_payment_details' => [
                            'transaction_id' => $transactionId,
                            'updated_total' => $newTotalAmount
                        ]
                    ],
                ],
            ], $context);
        } catch (\Exception $exception) {
            throw new APIException($cybersourceTransactionId, 'API_ERROR', $exception->getMessage());
        }

        return new JsonResponse($refundPaymentResponse);
    }

    public function canRefund($orderTransaction): bool
    {
        $paymentStatus = $this->getPaymentStatus($orderTransaction);
        return in_array($paymentStatus, [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_PARTIALLY_REFUNDED
        ]);
    }

    public function getOrderTransactionByOrderId(string $orderId, Context $context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('statemachinestate');
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        return $orderTransaction;
    }

    private function getCybersourcePaymentTransactionId($orderTransaction, $paymentStatus)
    {
        $customField = $orderTransaction->customFields;
        if (!empty($customField['cybersource_payment_details']['transaction_id'])) {
            return $customField['cybersource_payment_details']['transaction_id'];
        }

        if ($paymentStatus !== 'failed') {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.CYBERSOURCE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
    }

    private function getPaymentStatus($orderTransaction): ?string
    {
        $stateMachineStateEntity = $orderTransaction->getStateMachineState();
        return $stateMachineStateEntity->getTechnicalName();
    }

    private function transformLineItems($orderLineItems): array
    {
        $orderLineItemData = OrderMapper::formatLineItemData($orderLineItems);
        return $orderLineItemData;
    }

    private function getClientReference(OrderEntity $orderEntity): array
    {
        return OrderClientReferenceMapper::mapToClientReference(
            $orderEntity
        )->toArray();
    }

    #[Route(
        path: '/cybersource/capture-context',
        name: 'cybersource.capture_context',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function getCaptureContext(): JsonResponse
    {
        $signer = $this->configurationService->getSignatureContract();

        $endpoint = '/microform/v2/sessions';
        $domain = "https://".$_SERVER['HTTP_HOST'];
        $payload = json_encode([
            'captureMethod' => 'TOKEN',
            'targetOrigins' => [$domain],
            'allowedCardNetworks' => [
                "VISA", "MASTERCARD", "AMEX", "CARTESBANCAIRES", "CARNET", "CUP",
                "DINERSCLUB", "DISCOVER", "EFTPOS", "ELO", "JCB", "JCREW", "MADA",
                "MAESTRO", "MEEZA"
            ],
            'clientVersion' => 'v2'
        ]);

        $headers = $signer->getHeadersForPostMethod($endpoint, $payload);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        $response = $client->post($endpoint, [
            'headers' => $headers,
            'body' => $payload
        ]);

        $captureContext = (string) $response->getBody();
        return new JsonResponse([
            'captureContext' => $captureContext
        ]);
    }

    #[Route(
        path: '/cybersource/authorize-payment',
        name: 'cybersource.authorize_payment',
        methods: ['POST'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function authorizePayment(Request $request, SalesChannelContext $context): JsonResponse
    {
        $token = $request->request->get('token');
        if (!$token) {
            return new JsonResponse(['error' => 'Missing token'], 400);
        }

        $expirationMonth = $request->request->get('expirationMonth');
        $expirationYear = $request->request->get('expirationYear');

        $signer = $this->configurationService->getSignatureContract();
        $capture = $this->configurationService->getTransactionType() == 'auth_capture';
        $endpoint = '/pts/v2/payments';

        // Get cart and customer data from SalesChannelContext
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $customer = $context->getCustomer();
        $billingAddress = $customer ? $customer->getActiveBillingAddress() : null;

        // Dynamic amount and currency from cart
        $amount = $cart->getPrice()->getTotalPrice();
        $currency = $context->getCurrency()->getIsoCode();

        // Dynamic billTo from customer billing address
        $billTo = [];
        if ($billingAddress) {
            $billTo = [
                'firstName' => $billingAddress->getFirstName(),
                'lastName' => $billingAddress->getLastName(),
                'email' => $customer->getEmail(),
                'phoneNumber' => $billingAddress->getPhoneNumber() ?? '',
                'address1' => $billingAddress->getStreet(),
                'postalCode' => $billingAddress->getZipcode(),
                'locality' => $billingAddress->getCity(),
                'administrativeArea' => $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getShortCode() : '',
                'country' => $billingAddress->getCountry()->getIso()
            ];
        }

        $payload = json_encode([
            'clientReferenceInformation' => [
                'code' => 'Order-' . uniqid()
            ],
            'processingInformation' => [
                'commerceIndicator' => 'internet',
                'actionList' => ['CONSUMER_AUTHENTICATION'], // Request 3DS
                'capture' => $capture,
                'authorizationOptions' => [
                    'initiator' => 'merchant',
                    'initiateAuthenticationIndicator' => '01'
                ]
            ],
            'tokenInformation' => [
                'transientTokenJwt' => $token
            ],
            'sourceInformation' => [
                'source' => 'FlexMicroform'
            ],
            'paymentInformation' => [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear
                ]
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $amount,
                    'currency' => $currency
                ],
                'billTo' => $billTo
            ],
            'consumerAuthenticationInformation' => [
                'returnUrl' => 'https://' . $_SERVER['HTTP_HOST'] . '/3ds-callback'
            ]
        ]);

        $this->logger->info('Payment Request Payload: ' . $payload);

        $headers = $signer->getHeadersForPostMethod($endpoint, $payload);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);
        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payload
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->logger->info('Payment Response: ' . json_encode($responseData));

            $status = $responseData['status'] ?? 'UNKNOWN';
            $transactionId = $responseData['id'] ?? null;


            $orderId = null;
//            if (in_array($status, ['AUTHORIZED', 'AUTHORIZED_PENDING_REVIEW', 'PENDING_REVIEW'])) {
//                $orderId = $this->orderService->createOrder($cart, $context);
//                $this->logger->info('Order created with ID: ' . $orderId);
//
//                $this->updateOrderPaymentStatus($orderId, $status, $context);
//
//                $this->saveTransactionIdToOrder($orderId, $transactionId, $context);
//            }

            switch ($status) {
                case 'AUTHORIZED':
                    return new JsonResponse([
                        'success' => true,
                        'action' => 'complete',
                        'transactionId' => $transactionId,
                        'orderId' => $orderId,
                        'message' => 'Payment authorized successfully.'
                    ]);

                case 'PARTIAL_AUTHORIZED':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'transactionId' => $transactionId,
                        'message' => 'Payment partially authorized. Please contact support.'
                    ]);

                case 'AUTHORIZED_PENDING_REVIEW':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'transactionId' => $transactionId,
                        'orderId' => $orderId,
                        'message' => 'Payment has been authorized but is pending review by our team. We will notify you once the review is complete.'
                    ]);

                case 'AUTHORIZED_RISK_DECLINED':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'transactionId' => $transactionId,
                        'message' => 'Payment declined due to risk assessment. Please try a different payment method.'
                    ]);

                case 'PENDING_AUTHENTICATION':
                    $stepUpUrl = $responseData['consumerAuthenticationInformation']['stepUpUrl'] ?? null;
                    $accessToken = $responseData['consumerAuthenticationInformation']['token'] ?? null;

                    if ($stepUpUrl && $accessToken) {
                        return new JsonResponse([
                            'success' => true,
                            'action' => '3ds',
                            'transactionId' => $transactionId,
                            'stepUpUrl' => $stepUpUrl,
                            'accessToken' => $accessToken
                        ]);
                    }
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'message' => '3DS authentication required, but necessary information is missing.'
                    ]);

                case 'PENDING_REVIEW':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'transactionId' => $transactionId,
                        'orderId' => $orderId,
                        'message' => 'Payment is under review before authorization. We will notify you once the review is complete.'
                    ]);

                case 'DECLINED':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'transactionId' => $transactionId,
                        'message' => 'Payment declined. Please try a different payment method.'
                    ]);

                case 'INVALID_REQUEST':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'message' => 'Invalid payment request. Please check your details and try again.'
                    ]);

                default:
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'message' => 'Unknown payment status: ' . $status . '. Please contact support.'
                    ]);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $res = $e->hasResponse() ? $e->getResponse() : null;
            $body = $res ? $res->getBody()->getContents() : 'No response body';
            $this->logger->error('Payment Request Failed: ' . $e->getMessage(), ['response' => $body]);
            return new JsonResponse([
                'error' => 'Payment request failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(
        path: '/3ds-callback',
        name: 'cybersource.3ds_callback',
        methods: ['POST'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function handle3dsCallback(Request $request, SalesChannelContext $context): Response
    {
        $transactionId = $request->request->get('TransactionId');
        $paRes = $request->request->get('PaRes');
        $authenticationStatus = $request->request->get('AuthenticationStatus');

        $this->logger->info('3DS Callback received', [
            'transactionId' => $transactionId,
            'paRes' => $paRes,
            'authenticationStatus' => $authenticationStatus
        ]);

        if (!$transactionId || !$paRes) {
            $this->logger->error('Missing required 3DS callback parameters');
            return new Response('Missing required parameters', 400);
        }

        $signer = $this->configurationService->getSignatureContract();
        $endpoint = "/pts/v2/payments/{$transactionId}";
        $headers = $signer->getHeadersForGetMethod($endpoint);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->get($endpoint, [
                'headers' => $headers
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $status = $responseData['status'] ?? 'UNKNOWN';

            $this->logger->info('3DS Payment Status Check', [
                'transactionId' => $transactionId,
                'status' => $status
            ]);

            $orderId = null;

            if ($status === 'AUTHORIZED') {
                $action = 'complete';
                $message = '3DS authentication successful. Payment authorized.';
            } elseif ($status === 'DECLINED') {
                $action = 'notify';
                $message = '3DS authentication failed. Payment declined.';
            } else {
                $action = 'notify';
                $message = '3DS authentication completed, but payment status is: ' . $status;
            }

            $html = <<<HTML
            <script>
                window.parent.postMessage({
                    action: '$action',
                    message: '$message',
                    transactionId: '$transactionId',
                    orderId: '$orderId'
                }, '*');
            </script>
            HTML;

            return new Response($html, 200, ['Content-Type' => 'text/html']);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->logger->error('Failed to check payment status after 3DS', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage()
            ]);

            $html = <<<HTML
            <script>
                window.parent.postMessage({
                    action: 'notify',
                    message: 'Failed to verify payment status after 3DS: {$e->getMessage()}',
                    transactionId: '$transactionId'
                }, '*');
            </script>
            HTML;

            return new Response($html, 500, ['Content-Type' => 'text/html']);
        }
    }

}