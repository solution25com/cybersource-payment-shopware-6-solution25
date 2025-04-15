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
    private EntityRepository $customerRepository;

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
        LoggerInterface $logger,
        EntityRepository $customerRepository
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
        $this->customerRepository = $customerRepository;
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
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $subscriptionId = $data['subscriptionId'] ?? null;
        $saveCard = $data['saveCard'] ?? false;
        $expirationMonth = $data['expirationMonth'] ?? null;
        $expirationYear = $data['expirationYear'] ?? null;

        if (!$token && !$subscriptionId) {
            return new JsonResponse(['error' => 'Missing token or subscriptionId'], 400);
        }

        $signer = $this->configurationService->getSignatureContract();
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $customer = $context->getCustomer();
        $billingAddress = $customer ? $customer->getActiveBillingAddress() : null;

        $amount = (string) $cart->getPrice()->getTotalPrice();
        $currency = $context->getCurrency()->getIsoCode();

        $billTo = [];
        if ($billingAddress && $customer) {
            $billTo = [
                'firstName' => $billingAddress->getFirstName() ?? 'Unknown',
                'lastName' => $billingAddress->getLastName() ?? 'Unknown',
                'email' => $customer->getEmail() ?? 'no-email@example.com',
                'phoneNumber' => $billingAddress->getPhoneNumber() ?? '',
                'address1' => $billingAddress->getStreet() ?? 'Unknown Street',
                'postalCode' => $billingAddress->getZipcode() ?? '00000',
                'locality' => $billingAddress->getCity() ?? 'Unknown City',
                'administrativeArea' => $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getShortCode() : '',
                'country' => $billingAddress->getCountry()->getIso() ?? 'US'
            ];
        } else {
            $billTo = [
                'firstName' => 'Unknown',
                'lastName' => 'Unknown',
                'email' => 'no-email@example.com',
                'address1' => 'Unknown Street',
                'locality' => 'Unknown City',
                'country' => 'US',
                'postalCode' => '00000'
            ];
        }
        $uniqid = uniqid();
        $threeDSEnabled = $this->configurationService->isThreeDSEnabled();
        if (!$threeDSEnabled) {
            $authResponse = [
                'consumerAuthenticationInformation' => [
                    'eci' => null,
                    'cavv' => null
                ]
            ];
            return $this->completePayment($context, $authResponse, $saveCard, $uniqid, $token, $subscriptionId, $expirationMonth, $expirationYear);
        }

        $endpoint = '/risk/v1/authentications';
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $amount,
                    'currency' => $currency
                ],
                'billTo' => $billTo
            ],
            'consumerAuthenticationInformation' => [
                'authenticationType' => '01',
                'returnUrl' => 'https://' . $_SERVER['HTTP_HOST'] . '/cybersource/3ds-callback'
            ]
        ];

        if ($subscriptionId) {
            $payload['paymentInformation'] = [
                'customer' => [
                    'id' => $subscriptionId
                ]
            ];
        } else {
            $payload['tokenInformation'] = [
                'transientTokenJwt' => $token
            ];
            $payload['paymentInformation'] = [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear
                ]
            ];
        }

        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);
        $this->logger->info('Payer Authentication Request Payload: ' . $payloadJson);

        $headers = $signer->getHeadersForPostMethod($endpoint, $payloadJson);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->logger->info('Payer Authentication Response: ' . json_encode($responseData));

            $status = $responseData['status'] ?? 'UNKNOWN';
            $authenticationTransactionId = $responseData['consumerAuthenticationInformation']['authenticationTransactionId'] ?? null;

            if ($status === 'PENDING_AUTHENTICATION') {
                $acsUrl = $responseData['consumerAuthenticationInformation']['acsUrl'] ?? null;
                $pareq = $responseData['consumerAuthenticationInformation']['pareq'] ?? null;

                if ($acsUrl && $pareq && $authenticationTransactionId) {
                    return new JsonResponse([
                        'success' => true,
                        'action' => '3ds',
                        'authenticationTransactionId' => $authenticationTransactionId,
                        'acsUrl' => $acsUrl,
                        'pareq' => $pareq,
                        'uniqid' => $uniqid
                    ]);
                }
                return new JsonResponse([
                    'success' => false,
                    'action' => 'notify',
                    'message' => '3DS authentication required, but necessary information is missing.'
                ]);
            } elseif ($status === 'AUTHENTICATION_SUCCESSFUL') {
                return $this->completePayment($context, $responseData, $saveCard, $uniqid, $token, $subscriptionId, $expirationMonth, $expirationYear);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'action' => 'notify',
                    'message' => 'Payer authentication failed: ' . $status
                ]);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $res = $e->hasResponse() ? $e->getResponse() : null;
            $body = $res ? $res->getBody()->getContents() : 'No response body';
            $this->logger->error('Payer Authentication Request Failed: ' . $e->getMessage(), ['response' => $body]);
            return new JsonResponse([
                'error' => 'Payer authentication request failed',
                'message' => $body
            ], 500);
        }
    }

    /**
     * Complete the payment after 3DS authentication (or directly if 3DS is disabled)
     */
    private function completePayment(
        SalesChannelContext $context,
        array $authResponse,
        bool $saveCard,
        string $uniqid,
        ?string $transientTokenJwt = null,
        ?string $subscriptionId = null,
        ?string $expirationMonth = null,
        ?string $expirationYear = null
    ): JsonResponse {
        $signer = $this->configurationService->getSignatureContract();
        $capture = $this->configurationService->getTransactionType() == 'auth_capture';
        $endpoint = '/pts/v2/payments';

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $customer = $context->getCustomer();
        $billingAddress = $customer ? $customer->getActiveBillingAddress() : null;

        $amount = (string) $cart->getPrice()->getTotalPrice();
        $currency = $context->getCurrency()->getIsoCode();

        $billTo = [];
        if ($billingAddress && $customer) {
            $billTo = [
                'firstName' => $billingAddress->getFirstName() ?? 'Unknown',
                'lastName' => $billingAddress->getLastName() ?? 'Unknown',
                'email' => $customer->getEmail() ?? 'no-email@example.com',
                'phoneNumber' => $billingAddress->getPhoneNumber() ?? '',
                'address1' => $billingAddress->getStreet() ?? 'Unknown Street',
                'postalCode' => $billingAddress->getZipcode() ?? '00000',
                'locality' => $billingAddress->getCity() ?? 'Unknown City',
                'administrativeArea' => $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getShortCode() : '',
                'country' => $billingAddress->getCountry()->getIso() ?? 'US'
            ];
        } else {
            $billTo = [
                'firstName' => 'Unknown',
                'lastName' => 'Unknown',
                'email' => 'no-email@example.com',
                'address1' => 'Unknown Street',
                'locality' => 'Unknown City',
                'country' => 'US',
                'postalCode' => '00000'
            ];
        }

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid
            ],
            'processingInformation' => [
                'commerceIndicator' => 'internet',
                'actionList' => ['TOKEN_CREATE'],
                'capture' => $capture
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $amount,
                    'currency' => $currency
                ],
                'billTo' => $billTo
            ]
        ];

        $threeDSEnabled = $this->configurationService->isThreeDSEnabled();
        if ($threeDSEnabled) {
            $payload['consumerAuthenticationInformation'] = [
                'eci' => $authResponse['consumerAuthenticationInformation']['eci'] ?? null,
                'cavv' => $authResponse['consumerAuthenticationInformation']['cavv'] ?? null
            ];
        }

        if ($subscriptionId) {
            $payload['paymentInformation'] = [
                'customer' => [
                    'id' => $subscriptionId
                ]
            ];
        } else {
            if (!$transientTokenJwt) {
                $this->logger->error('transientTokenJwt is missing in completePayment', [
                    'authResponse' => $authResponse,
                    'subscriptionId' => $subscriptionId
                ]);
                return new JsonResponse([
                    'success' => false,
                    'action' => 'notify',
                    'message' => 'Payment failed: Missing transientTokenJwt.'
                ], 400);
            }

            $payload['tokenInformation'] = [
                'transientTokenJwt' => $transientTokenJwt
            ];
            $payload['paymentInformation'] = [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear
                ]
            ];
        }

        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);
        $this->logger->info('Payment Request Payload: ' . $payloadJson);

        $headers = $signer->getHeadersForPostMethod($endpoint, $payloadJson);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->logger->info('Payment Response: ' . json_encode($responseData));

            $status = $responseData['status'] ?? 'UNKNOWN';
            $transactionId = $responseData['id'] ?? null;

            if ($saveCard && $transactionId && $status === 'AUTHORIZED') {
                $instrumentIdentifierId = $responseData['tokenInformation']['instrumentIdentifier']['id'] ?? null;
                if (!$instrumentIdentifierId) {
                    $this->logger->error('Instrument identifier not found in payment response', [
                        'response' => $responseData,
                    ]);
                } else {
                    $this->logger->info('Saving card with instrumentIdentifierId: ' . $instrumentIdentifierId);
                    $saveSuccess = $this->saveCard($context, $instrumentIdentifierId);
                    if (!$saveSuccess) {
                        $this->logger->warning('Failed to save card', [
                            'instrumentIdentifierId' => $instrumentIdentifierId,
                        ]);
                    }
                }
            }

            switch ($status) {
                case 'AUTHORIZED':
                    return new JsonResponse([
                        'success' => true,
                        'action' => 'complete',
                        'status' => $status,
                        'transactionId' => $transactionId,
                        'uniqid' => $uniqid,
                        'message' => 'Payment authorized successfully.'
                    ]);

                case 'PARTIAL_AUTHORIZED':
                    return new JsonResponse([
                        'success' => true,
                        'action' => 'complete',
                        'transactionId' => $transactionId,
                        'status' => $status,
                        'uniqid' => $uniqid,
                        'message' => 'Payment partially authorized. Please contact support.'
                    ]);

                case 'AUTHORIZED_PENDING_REVIEW':
                    return new JsonResponse([
                        'success' => true,
                        'action' => 'complete',
                        'status' => $status,
                        'transactionId' => $transactionId,
                        'uniqid' => $uniqid,
                        'message' => 'Payment has been authorized but is pending review by our team.'
                    ]);

                case 'DECLINED':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'transactionId' => $transactionId,
                        'message' => 'Payment declined. Please try a different payment method.'
                    ]);

                default:
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'message' => 'Payment failed: ' . $status
                    ]);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $res = $e->hasResponse() ? $e->getResponse() : null;
            $body = $res ? $res->getBody()->getContents() : 'No response body';
            $this->logger->error('Payment Request Failed: ' . $e->getMessage(), ['response' => $body]);
            return new JsonResponse([
                'error' => 'Payment request failed',
                'message' => $body
            ], 500);
        }
    }
    public function saveCard(SalesChannelContext $context, string $paymentToken): bool
    {
        $customer = $context->getCustomer();
        if (!$customer) {
            $this->logger->warning('No customer found to save card', [
                'salesChannelId' => $context->getSalesChannelId(),
            ]);
            return false;
        }

        $customerTokenId = $customer->getCustomFields()['cybersource_customer_token'] ?? null;
        if (!$customerTokenId) {
            $signer = $this->configurationService->getSignatureContract();
            $endpoint = '/tms/v2/customers';
            $payload = json_encode([
                'customerInformation' => [
                    'email' => $customer->getEmail() ?? 'no-email@example.com',
                ],
            ]);
            $headers = $signer->getHeadersForPostMethod($endpoint, $payload);
            $base_url = $this->configurationService->getBaseUrl()->value;
            $client = new Client(['base_uri' => $base_url]);

            try {
                $response = $client->post($endpoint, [
                    'headers' => $headers,
                    'body' => $payload,
                ]);
                $responseData = json_decode($response->getBody()->getContents(), true);
                $customerTokenId = $responseData['id'] ?? null;

                if (!$customerTokenId) {
                    throw new \RuntimeException('Failed to create customer token: No customerTokenId returned');
                }

                // Save customerTokenId to customer custom fields
                $this->customerRepository->update([
                    [
                        'id' => $customer->getId(),
                        'customFields' => array_merge(
                            $customer->getCustomFields() ?? [],
                            ['cybersource_customer_token' => $customerTokenId]
                        ),
                    ],
                ], $context->getContext());
            } catch (\Exception $e) {
                $this->logger->error('Failed to create customer token', [
                    'error' => $e->getMessage(),
                    'response' => $e instanceof \GuzzleHttp\Exception\ClientException ? $e->getResponse()->getBody()->getContents() : null,
                ]);
                return false;
            }
        }

        // Step 2: Associate instrumentIdentifier.id with customerTokenId
        $signer = $this->configurationService->getSignatureContract();
        $endpoint = "/tms/v2/customers/{$customerTokenId}/payment-instruments";
        $payload = json_encode([
            'instrumentIdentifier' => [
                'id' => $paymentToken, // This is the instrumentIdentifier.id (7036200001260191005)
            ],
        ]);
        $headers = $signer->getHeadersForPostMethod($endpoint, $payload);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payload,
            ]);

            // Save instrumentIdentifier.id to customer custom fields (for reference)
            $this->customerRepository->update([
                [
                    'id' => $customer->getId(),
                    'customFields' => array_merge(
                        $customer->getCustomFields() ?? [],
                        ['cybersource_payment_token' => $paymentToken]
                    ),
                ],
            ], $context->getContext());

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to associate instrument identifier with customer token', [
                'error' => $e->getMessage(),
                'response' => $e instanceof \GuzzleHttp\Exception\ClientException ? $e->getResponse()->getBody()->getContents() : null,
            ]);
            return false;
        }
    }

    #[Route(
        path: '/cybersource/get-saved-cards',
        name: 'cybersource.get_saved_cards',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function getSavedCards(SalesChannelContext $context): JsonResponse
    {
        $customer = $context->getCustomer();
        if (!$customer) {
            return new JsonResponse(['cards' => []]);
        }

        $customerTokenId = $customer->getCustomFields()['cybersource_customer_token'] ?? null;
        if (!$customerTokenId) {
            $this->logger->warning('No customer token found for customer', [
                'customerId' => $customer->getId(),
                'customFields' => $customer->getCustomFields(),
            ]);
            return new JsonResponse(['cards' => []]);
        }

        $signer = $this->configurationService->getSignatureContract();
        $endpoint = "/tms/v2/customers/{$customerTokenId}/payment-instruments";
        $headers = $signer->getHeadersForGetMethod($endpoint);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->get($endpoint, [
                'headers' => $headers,
            ]);
            $responseData = json_decode($response->getBody()->getContents(), true);
            $cards = $responseData['_embedded']['paymentInstruments'] ?? [];
            return new JsonResponse(['cards' => $cards]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch saved cards', [
                'error' => $e->getMessage(),
                'response' => $e instanceof \GuzzleHttp\Exception\ClientException ? $e->getResponse()->getBody()->getContents() : null,
            ]);
            return new JsonResponse(['error' => 'Failed to fetch saved cards'], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/cybersource/3ds-callback',
        name: 'cybersource.3ds_callback',
        methods: ['POST'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function handle3dsCallback(Request $request, SalesChannelContext $context): JsonResponse
    {
        $authenticationTransactionId = $request->request->get('authenticationTransactionId');
        $pares = $request->request->get('PaRes');
        $uniqid = $request->request->get('uniqid');

        if (!$authenticationTransactionId || !$pares || !$uniqid) {
            return new JsonResponse(['error' => 'Missing required parameters'], 400);
        }

        // Step 1: Retrieve authentication results
        $signer = $this->configurationService->getSignatureContract();
        $endpoint = '/risk/v1/authentication-results';
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid
            ],
            'consumerAuthenticationInformation' => [
                'authenticationTransactionId' => $authenticationTransactionId,
                'pares' => $pares
            ]
        ];

        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);
        $this->logger->info('Authentication Results Request Payload: ' . $payloadJson);

        $headers = $signer->getHeadersForPostMethod($endpoint, $payloadJson);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->logger->info('Authentication Results Response: ' . json_encode($responseData));

            $status = $responseData['status'] ?? 'UNKNOWN';

            if ($status === 'AUTHENTICATION_SUCCESSFUL') {
                $transientTokenJwt = $request->request->get('transientTokenJwt');
                $subscriptionId = $request->request->get('subscriptionId');
                $expirationMonth = $request->request->get('expirationMonth');
                $expirationYear = $request->request->get('expirationYear');

                return $this->completePayment($context, $responseData, true, $uniqid, $transientTokenJwt, $subscriptionId, $expirationMonth, $expirationYear);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'action' => 'notify',
                    'message' => '3DS authentication failed: ' . $status
                ]);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $res = $e->hasResponse() ? $e->getResponse() : null;
            $body = $res ? $res->getBody()->getContents() : 'No response body';
            $this->logger->error('Authentication Results Request Failed: ' . $e->getMessage(), ['response' => $body]);
            return new JsonResponse([
                'error' => 'Failed to retrieve authentication results',
                'message' => $body
            ], 500);
        }
    }

}