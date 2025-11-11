<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use CyberSource\Shopware6\Library\RequestSignature\HTTP;
use CyberSource\Shopware6\Library\RequestSignature\JWT;
use CyberSource\Shopware6\Library\RequestSignature\Oauth;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Checkout\Customer\CustomerCollection as CustomerEntityCollection;
use Shopware\Core\Checkout\Order\OrderCollection as OrderEntityCollection;

class CyberSourceApiClient
{
    private ConfigurationService $configurationService;
    /**
     * @var EntityRepository<CustomerEntityCollection>
     */
    private EntityRepository $customerRepository;
    private CartService $cartService;
    private LoggerInterface $logger;
    private Client $client;
    private string $baseUrl;
    private HTTP|JWT|Oauth $signer;
    /**
     * @var EntityRepository<OrderEntityCollection>
     */
    private EntityRepository $orderRepository;
    private OrderService $orderService;
    private OrderTransactionStateHandler $orderTransactionStateHandler;
    private StateMachineRegistry $stateMachineRegistry;
    private TransactionLogger $transactionLogger;
    private AmountService $amountService;
    private RequestStack $requestStack;
    /**
     * @param EntityRepository<CustomerEntityCollection> $customerRepository
     * @param EntityRepository<OrderEntityCollection> $orderRepository
     */
    public function __construct(
        ConfigurationService $configurationService,
        CartService $cartService,
        EntityRepository $customerRepository,
        LoggerInterface $logger,
        EntityRepository $orderRepository,
        OrderService $orderService,
        TransactionLogger $transactionLogger,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        StateMachineRegistry $stateMachineRegistry,
        AmountService $amountService,
        RequestStack $requestStack,
    ) {
        $this->configurationService = $configurationService;
        $this->cartService = $cartService;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->transactionLogger = $transactionLogger;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->amountService = $amountService;
        $this->requestStack = $requestStack;
    }

    public function resolveFingerprintSessionToken(): ?string
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            if ($request instanceof Request) {
                $token = $request->headers->get('sw-context-token')
                    ?: $request->get('sw-context-token')
                    ?: $request->cookies->get('sw-context-token');
                if (!$token && $request->hasSession()) {
                    $token = $request->getSession()->getId();
                }
                if ($token && is_string($token)) {
                    return trim($token);
                }
            }
            $sid = \function_exists('session_id') ? \session_id() : '';
            return $sid ?: null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve fingerprint session token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function shouldAttachFingerprint(?string $salesChannelId = null): bool
    {
        if (!$this->configurationService->isFingerprintEnabled($salesChannelId)) {
            return false;
        }
        $orgId = $this->configurationService->getFingerprintOrganizationId($salesChannelId);
        $merchantId = $this->configurationService->getMerchantId($salesChannelId);
        if (!$orgId || !$merchantId) {
            $this->logger->warning('Fingerprinting enabled but organizationId or merchantId missing. Skipping.', [
                'organizationId' => $orgId,
                'merchantId' => $merchantId,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Build the same session_id value used in fingerprint URLs.
     */
    private function buildFingerprintSessionId(?string $salesChannelId = null): ?string
    {
        try {
            $merchantId = $this->configurationService->getMerchantId($salesChannelId);
            $sessionToken = $this->resolveFingerprintSessionToken();
            if (!$merchantId || !$sessionToken) {
                return null;
            }
            if (strpos($sessionToken, $merchantId) === 0) {
                return $sessionToken;
            }
            return $merchantId . $sessionToken;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Generate headers for a request based on the HTTP method.
     */
    private function generateHeaders(string $method, string $endpoint, string $payloadJson): array
    {
        switch (strtolower($method)) {
            case 'post':
                return $this->signer->getHeadersForPostMethod($endpoint, $payloadJson);
            case 'get':
                return $this->signer->getHeadersForGetMethod($endpoint);
            case 'patch':
                return $this->signer->getHeadersForPatchMethod($endpoint, $payloadJson);
            case 'put':
                return $this->signer->getHeadersForPutMethod($endpoint, $payloadJson);
            case 'delete':
                return $this->signer->getHeadersForDeleteMethod($endpoint);
            default:
                throw new \InvalidArgumentException("Unsupported HTTP method: {$method}");
        }
    }

    /**
     * Execute an HTTP request with centralized error handling and logging.
     */
    private function executeRequest(
        string $method,
        string $endpoint,
        array  $payload = [],
        string $logContext = 'API Request',
        string $salesChannelId = null
    ): array
    {
        $this->signer = $this->configurationService->getSignatureContract($salesChannelId);
        $this->baseUrl = $this->configurationService->getBaseUrl($salesChannelId)->value;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
        ]);
        if(!$salesChannelId){
            $salesChannelId = null;
        }

        if (strtolower($method) === 'post') {
            $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp();
            if ($clientIp) {
                $payload['deviceInformation']['ipAddress'] = $clientIp;
            }
            if ($this->shouldAttachFingerprint($salesChannelId)) {
                $fpSessionId = $this->resolveFingerprintSessionToken();
                if ($fpSessionId) {
                    $payload['deviceInformation']['fingerprintSessionId'] = $fpSessionId;
                }
            }
        }

        $payloadJson = !empty($payload) ? json_encode($payload, JSON_PRETTY_PRINT) : '';
        if ($payloadJson === false) {
            throw new \RuntimeException('Failed to encode payload to JSON');
        }
        $headers = $this->generateHeaders($method, $endpoint, $payloadJson);

        try {
            $options = ['headers' => $headers];
            if ($payloadJson) {
                $options['body'] = $payloadJson;
            }
            $response = $this->client->{strtolower($method)}($endpoint, $options);
            $responseBody = $response->getBody()->getContents();
            $responseReturn = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $responseReturn = $responseBody;
            }

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => $responseReturn ?: []
            ];
        } catch (GuzzleException $e) {
            $errorResponse = $e instanceof \GuzzleHttp\Exception\ClientException && $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $errorResponse = $this->formatCyberSourceError($errorResponse);
            $this->logger->error("{$logContext} Failed: " . $e->getMessage(), [
                'endpoint' => $endpoint,
                'payload' => $payload,
                'response' => $errorResponse,
            ]);
            throw new \RuntimeException("{$logContext} failed: " . $errorResponse);
        }
    }

    public function formatCyberSourceError(string|array $response): string
    {
        try {
            if (is_string($response)) {
                $response = json_decode($response, true);
            }
            $output = $response['message'] . "\n";

            if (!empty($response['details'])) {
                foreach ($response['details'] as $detail) {
                    $output .= "- " . $detail['field'] . " - " . $detail['reason'] . "\n";
                }
            }
            return trim($output);
        } catch (\Exception $e) {
            return "Failed to format error response: " . $e->getMessage();
        }
    }

    /**
     * Build billing information from customer and address.
     */
    private function buildBillTo(?CustomerEntity $customer, ?CustomerAddressEntity $billingAddress): array
    {
        if (!$billingAddress || !$customer) {
            return [
                'firstName' => 'Unknown',
                'lastName' => 'Unknown',
                'email' => 'no-email@example.com',
                'address1' => 'Unknown Street',
                'locality' => 'Unknown City',
                'administrativeArea' => 'Unknown State',
                'country' => 'US',
                'postalCode' => '00000',
            ];
        }
        $country = $billingAddress->getCountry();
        $countryCode = $country instanceof CountryEntity ? $country->getIso() : 'US';
        $billTo = [
            'firstName' => $billingAddress->getFirstName() ?: 'Unknown',
            'lastName' => $billingAddress->getLastName() ?: 'Unknown',
            'email' => $customer->getEmail() ?: 'no-email@example.com',
            'address1' => $billingAddress->getStreet() ?: 'Unknown Street',
            'postalCode' => $billingAddress->getZipcode() ?: '00000',
            'locality' => $billingAddress->getCity() ?: 'Unknown City',
            'country' => $countryCode,
        ];

        if ($billingAddress->getPhoneNumber()) {
            $billTo['phoneNumber'] = $billingAddress->getPhoneNumber();
        }
        if ($billingAddress->getCountryState()) {
            $shortCode = $billingAddress->getCountryState()->getShortCode();
            if (strpos($shortCode, '-') !== false) {
                $shortCode = explode('-', $shortCode);
                if (count($shortCode) > 1) {
                    $billTo['administrativeArea'] = $shortCode[1];
                }
            } elseif ($shortCode) {
                $billTo['administrativeArea'] = $shortCode;
            }
        }

        return $billTo;
    }

    /**
     * Create a shared secret key.
     */
    public function createKey(array $payload): array
    {
        return $this->executeRequest(
            'Post',
            '/kms/egress/v2/keys-sym',
            $payload,
            'Create Key'
        );
    }

    /**
     * Create a webhook.
     */
    public function createWebhook(array $payload, ?string $salesChannelId = null): array
    {
        return $this->executeRequest(
            'Post',
            '/notification-subscriptions/v2/webhooks',
            $payload,
            'Create Webhook'
        );
    }

    /**
     * Read a webhook.
     */
    public function readWebhook(string $webhookId): array
    {
        return $this->executeRequest(
            'Get',
            "/notification-subscriptions/v2/webhooks/{$webhookId}",
            [],
            'Read Webhook'
        );
    }

    /**
     * Read a webhook.
     */
    public function readAllWebhook(string $orgId): array
    {
        return $this->executeRequest(
            'Get',
            "/notification-subscriptions/v2/webhooks?organizationId={$orgId}",
            [],
            'Read Webhooks'
        );
    }

    /**
     * Update a webhook.
     */
    public function updateWebhook(string $webhookId, array $payload): array
    {
        return $this->executeRequest(
            'put',
            "/notification-subscriptions/v2/webhooks/{$webhookId}/status",
            $payload,
            'Update Webhook'
        );
    }

    /**
     * Delete a webhook.
     */
    public function deleteWebhook(string $webhookId): array
    {
        return $this->executeRequest(
            'Delete',
            "/notification-subscriptions/v2/webhooks/{$webhookId}",
            [],
            'Delete Webhook'
        );
    }

    /**
     * Retrieve transaction details by transaction ID.
     */
    public function retrieveTransaction(string $cybersourcePaymentId, array $payload, string $salesChannelId = null): array
    {
        try {
            return $this->executeRequest(
                'GET',
                "/tss/v2/transactions/{$cybersourcePaymentId}",
                [],
                'Retrieve Transaction',
                $salesChannelId
            );
        } catch (\RuntimeException $e) {
            return [
                'statusCode' => 401,
                'body' => []
            ];
        }
    }

    /**
     * Retrieve transaction details by transaction ID.
     */
    public function retrieveTransientToken(string $transientToken, array $payload, string $salesChannelId = null): array
    {
        try {
            return $this->executeRequest(
                'GET',
                "/up/v1/payment-details/{$transientToken}",
                [],
                'Retrieve Transaction',
                $salesChannelId
            );
        } catch (\RuntimeException $e) {
            return [
                'statusCode' => 401,
                'body' => []
            ];
        }
    }

    /**
     * Capture a payment.
     */
    public function capturePayment(
        string  $transactionId,
        array   $payload,
        string  $orderTransactionId,
        Context $context,
        string $salesChannelId = null
    ): array {
        $response = $this->executeRequest(
            'Post',
            "/pts/v2/payments/{$transactionId}/captures",
            $payload,
            'Capture Payment',
            $salesChannelId
        );
        $responseData = $response['body'];
        $responseData['statusCode'] = $response['statusCode'];
        $responseData['id'] = $response['body']['id'] ?? $transactionId;
        $this->transactionLogger->logTransaction(
            'Payment',
            $responseData,
            $orderTransactionId,
            $context
        );
        return $response;
    }

    /**
     * Void a payment.
     */
    public function voidPayment(
        string  $transactionId,
        array   $payload,
        string  $orderTransactionId,
        Context $context,
        ?string $salesChannelId = null
    ): array {
        $response = $this->executeRequest(
            'Post',
            "/pts/v2/payments/{$transactionId}/reversals",
            $payload,
            'Void Payment',
            $salesChannelId
        );
        $responseData = $response['body'];
        $responseData['statusCode'] = $response['statusCode'];
        $responseData['id'] = $response['body']['id'] ?? $transactionId;
        $this->transactionLogger->logTransaction(
            'Void',
            $responseData,
            $orderTransactionId,
            $context,
            $salesChannelId
        );
        return $response;
    }

    /**
     * Refund a payment.
     */
    public function refundPayment(
        string  $transactionId,
        array   $payload,
        string  $orderTransactionId,
        Context $context,
        ?string $salesChannelId = null
    ): array {
        $response = $this->executeRequest(
            'Post',
            "/pts/v2/payments/{$transactionId}/refunds",
            $payload,
            'Refund Payment',
            $salesChannelId
        );
        $responseData = $response['body'];
        $responseData['statusCode'] = $response['statusCode'];
        $responseData['id'] = $response['body']['id'] ?? $transactionId;
        $this->transactionLogger->logTransaction(
            'Refund',
            $responseData,
            $orderTransactionId,
            $context
        );
        return $response;
    }

    /**
     * Process a payment action (capture, void, refund, re-authorize).
     */
    public function processPaymentAction(
        string  $action,
        string  $transactionId,
        array   $payload,
        string  $orderId,
        Context $context
    ): array {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('currency');
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        if (!$order) {
            $this->logger->error('Order not found for ID', [
                'orderId' => $orderId,
                'action' => $action,
                'transactionId' => $transactionId,
            ]);
            return [
                'status' => 'error',
                'message' => 'Order not found. Please verify the order ID and try again.',
                'data' => []
            ];
        }

        $transaction = $order->getTransactions()?->first();
        if (!$transaction) {
            $this->logger->error('Transaction not found for order', [
                'orderId' => $orderId,
                'action' => $action,
                'transactionId' => $transactionId,
            ]);
            return [
                'status' => 'error',
                'message' => 'No transaction found for this order. Please contact support.',
                'data' => []
            ];
        }

        $orderTransactionId = $transaction->getId();
        $customFields = $transaction->getCustomFields() ?? [];
        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($customFields);
        if (!$cybersourceTransactionId) {
            $this->logger->error('CyberSource transaction ID not found', [
                'orderId' => $orderId,
                'orderTransactionId' => $orderTransactionId,
                'action' => $action,
                'customFields' => $customFields,
            ]);
            return [
                'status' => 'error',
                'message' => 'Unable to process the request due to missing payment details. Please contact support.',
                'data' => []
            ];
        }

        $uniqId = $this->orderService->getCyberSourceTransactionUniqueId($customFields);
        $payload['clientReferenceInformation'] = [
            'code' => 'Order-' . ($uniqId ?? $cybersourceTransactionId),
        ];
        $salesChannelId = $order->getSalesChannelId();
        $action = strtoupper($action);
        $method = 'Post';
        $endpoint = '';
        $logType = '';
        $actionFriendlyName = '';
        switch ($action) {
            case 'CAPTURE':
                $endpoint = "/pts/v2/payments/{$transactionId}/captures";
                $logType = 'Payment';
                $actionFriendlyName = 'payment capture';
                break;
            case 'VOID':
                $endpoint = "/pts/v2/payments/{$transactionId}/reversals";
                $logType = 'Void';
                $actionFriendlyName = 'payment cancellation';
                break;
            case 'REFUND':
                $endpoint = "/pts/v2/payments/{$transactionId}/refunds";
                $logType = 'Refund';
                $actionFriendlyName = 'refund';
                break;
            case 'REAUTHORIZE':
                $endpoint = "/pts/v2/payments/{$transactionId}";
                $logType = 'Re-Authorization';
                $actionFriendlyName = 're-authorization';
                $method = 'Patch';
                break;
            default:
                $this->logger->error('Unsupported payment action', [
                    'action' => $action,
                    'orderId' => $orderId,
                    'orderTransactionId' => $orderTransactionId,
                ]);
                return [
                    'status' => 'error',
                    'message' => 'Invalid payment action requested. Please contact support.',
                    'data' => []
                ];
        }

        try {
            $response = $this->executeRequest($method, $endpoint, $payload, $logType, $salesChannelId);
            $responseData = $response['body'];


            $customPaymentDetails = $customFields['cybersource_payment_details']['transactions'][0] ?? [];
            $mergedData = array_merge($responseData, [
                'id' => $cybersourceTransactionId,
                'clientReferenceInformation' => $responseData['clientReferenceInformation'] ??
                    ['code' => $payload['clientReferenceInformation']['code']],
                'paymentInformation' => [
                    'card' => [
                        'type' => $responseData['paymentInformation']['card']['type'] ??
                                $customPaymentDetails['card_category'] ?? null,
                        'brand' => $responseData['paymentInformation']['card']['brand'] ??
                                $customPaymentDetails['payment_method_type'] ?? null,
                        'expirationMonth' => $responseData['paymentInformation']['card']['expirationMonth'] ??
                                $customPaymentDetails['expiry_month'] ?? null,
                        'expirationYear' => $responseData['paymentInformation']['card']['expirationYear'] ??
                                $customPaymentDetails['expiry_year'] ?? null,
                        'number' =>
                            $customPaymentDetails['card_last_4'] ? '****' . $customPaymentDetails['card_last_4'] :
                                ($responseData['paymentInformation']['card']['number'] ?? null),
                    ],
                ],
                'processorInformation' => [
                    'approvalCode' => $responseData['processorInformation']['approvalCode'] ??
                            $customPaymentDetails['gateway_authorization_code'] ?? null,
                    'transactionId' => $responseData['processorInformation']['transactionId'] ??
                            $customPaymentDetails['gateway_reference'] ?? null,
                ],
                'tokenInformation' => [
                    'paymentInstrument' => [
                        'id' => $responseData['tokenInformation']['paymentInstrument']['id'] ??
                                $customPaymentDetails['gateway_token'] ?? null,
                    ],
                ],
                'orderInformation' => $payload['orderInformation'] ?? ($payload['reversalInformation'] ?? [])
            ]);
            if ($action === 'REAUTHORIZE') {
                $mergedData['orderInformation']['amountDetails']['totalAmount'] =
                    $mergedData['orderInformation']['amountDetails']['additionalAmount'] ?? [];
            }
            $mergedData['statusCode'] = $response['statusCode'];
            $mergedData['id'] = $response['body']['id'] ?? $transactionId;

            $this->transactionLogger->logTransaction($logType, $mergedData, $orderTransactionId, $context, $uniqId);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                return [
                    'status' => 'success',
                    'message' => 'The ' . $actionFriendlyName . ' was completed successfully.',
                    'data' => $response['body']
                ];
            } else {
                $this->logger->error(ucfirst(strtolower($action)) . ' failed', [
                    'orderId' => $orderId,
                    'orderTransactionId' => $orderTransactionId,
                    'response' => $response['body'],
                ]);
                return [
                    'status' => 'error',
                    'message' => 'Failed to process the ' . $actionFriendlyName . '. Error: ' .
                        ($response['body']['message'] ?? 'Unknown error occurred.'),
                    'data' => $response['body']
                ];
            }
        } catch (\RuntimeException $e) {
            $this->logger->error(ucfirst(strtolower($action)) . ' failed for order', [
                'action' => $action,
                'orderId' => $orderId,
                'orderTransactionId' => $orderTransactionId,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 'error',
                'message' => 'Unable to process the ' . $actionFriendlyName . '. Please try again or contact support.',
                'data' => []
            ];
        }
    }

    /**
     * Get configuration service.
     */
    public function getConfigurationService(): ConfigurationService
    {
        return $this->configurationService;
    }

    /**
     * Get capture context for Microform.
     */
    public function getCaptureContext(string $salesChannelId = null): JsonResponse
    {
        $endpoint = '/microform/v2/sessions';
        $domain = "https://" . $_SERVER['HTTP_HOST'];
        $payload = [
            'captureMethod' => 'TOKEN',
            'targetOrigins' => [$domain],
            'allowedCardNetworks' => [
                "VISA", "MASTERCARD", "AMEX", "CARTESBANCAIRES", "CARNET", "CUP",
                "DINERSCLUB", "DISCOVER", "EFTPOS", "ELO", "JCB", "JCREW", "MADA",
                "MAESTRO", "MEEZA",
            ],
            'clientVersion' => 'v2',
        ];

        try {
            $response = $this->executeRequest('Post', $endpoint, $payload, 'Get Capture Context', $salesChannelId);
            return new JsonResponse(['captureContext' => $response['body']]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()]);
        }
    }

    /**
     * Authorize a payment.
     */
    public function authorizePayment(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $subscriptionId = $data['subscriptionId'] ?? null;
        $saveCard = $data['saveCard'] ?? false;
        $expirationMonth = $data['expirationMonth'] ?? null;
        $expirationYear = $data['expirationYear'] ?? null;
        $orderId = $data['orderId'] ?? null;
        $billTo = $data['billingAddress'] ?? null;
        $salesChannelId = $context->getSalesChannel()->getId();
        if (!$token && !$subscriptionId) {
            return new JsonResponse(['error' => 'Missing token or subscriptionId'], 400);
        }
        $customer = $context->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 403);
        }
        $customerId = $customer->getId();
        $billingAddress = $customer->getActiveBillingAddress();
        if ($orderId) {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('currency');
            $order = $this->orderRepository->search($criteria, $context->getContext())->first();
            if (!$order) {
                return new JsonResponse(['error' => 'Order not found'], 404);
            }
            $amount = (string)$this->amountService->getAmount($order);
            $currency = $order->getCurrency();
            $currency = $currency instanceof CurrencyEntity ? $currency->getIsoCode() :
                $context->getCurrency()->getIsoCode();
        } else {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $amount = (string)$this->amountService->getAmount($cart);
            $currency = $context->getCurrency()->getIsoCode();
        }
        $uniqid = uniqid();
        if ($billTo) {
            $shortCode = $billTo['state'];
            if (strpos($shortCode, '-') !== false) {
                $shortCode = explode('-', $shortCode);
                if (count($shortCode) > 1) {
                    $billTo['administrativeArea'] = $shortCode[1];
                }
            } elseif ($shortCode) {
                $billTo['administrativeArea'] = $shortCode;
            }
            unset($billTo['state']);
            $billTo['email'] = $customer->getEmail();
        } else {
            $billTo = $this->buildBillTo($customer, $billingAddress);
        }

        $orderInfo = [
            'amountDetails' => [
                'totalAmount' => $amount,
                'currency' => $currency,
            ],
            'billTo' => $billTo,
        ];

        if ($subscriptionId) {
            unset($orderInfo['billTo']);
            $saveCard = false;
        }

        if (!$this->configurationService->isThreeDSEnabled($salesChannelId)) {
            return $this->completePayment(
                $context,
                [],
                $saveCard,
                $uniqid,
                $token,
                $subscriptionId,
                $expirationMonth,
                $expirationYear,
                $orderInfo,
                $customerId,
                $salesChannelId
            );
        }

        $setupPayload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid,
            ],
            'orderInformation' => $orderInfo,
        ];

        if ($subscriptionId) {
            $cardCustomerId = $this->getCardCustomerId($subscriptionId, $context);
            $setupPayload['paymentInformation'] = [
                'customer' => [
                    'id' => $cardCustomerId,
                ],
                'paymentInstrument' => [
                    'id' => $subscriptionId,
                ]
            ];
        } else {
            $setupPayload['tokenInformation'] = [
                'transientTokenJwt' => $token,
            ];
            $setupPayload['paymentInformation'] = [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear,
                ],
            ];
        }

        try {
            $response = $this->executeRequest(
                'Post',
                '/risk/v1/authentication-setups',
                $setupPayload,
                'Authentication Setup',
                $salesChannelId
            );
            return new JsonResponse([
                'success' => true,
                'action' => 'setup',
                'uniqid' => $uniqid,
                'consumerAuthenticationInformation' => $response['body']['consumerAuthenticationInformation'],
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => 'Authentication setup failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Proceed with 3DS authentication.
     */
    public function proceedAuthentication(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $subscriptionId = $data['subscriptionId'] ?? null;
        $saveCard = $data['saveCard'] ?? false;
        $expirationMonth = $data['expirationMonth'] ?? null;
        $expirationYear = $data['expirationYear'] ?? null;
        $setupResponse = $data['setupResponse'] ?? null;
        $callbackData = $data['callbackData'] ?? null;
        $uniqid = $data['uniqid'] ?? null;
        $billTo = $data['billingAddress'] ?? null;
        $salesChannelId = $context->getSalesChannel()->getId();
        if (!$token && !$subscriptionId) {
            return new JsonResponse(['error' => 'Missing token or subscriptionId'], 400);
        }
        if (!$setupResponse || !$callbackData || !$uniqid) {
            return new JsonResponse(['error' => 'Missing setup response, callback data, or uniqid'], 400);
        }

        $setupResponse = is_string($setupResponse) ? json_decode($setupResponse, true) : $setupResponse;
        $callbackData = is_string($callbackData) ? json_decode($callbackData, true) : $callbackData;

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $customer = $context->getCustomer();
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 403);
        }
        $customerId = $customer->getId();
        $billingAddress = $customer->getActiveBillingAddress();
        $amount = (string)$this->amountService->getAmount($cart);
        $currency = $context->getCurrency()->getIsoCode();
        if ($billTo) {
            $shortCode = $billTo['state'];
            if (strpos($shortCode, '-') !== false) {
                $shortCode = explode('-', $shortCode);
                if (count($shortCode) > 1) {
                    $billTo['administrativeArea'] = $shortCode[1];
                }
            } elseif ($shortCode) {
                $billTo['administrativeArea'] = $shortCode;
            }
            unset($billTo['state']);
        } else {
            $billTo = $this->buildBillTo($customer, $billingAddress);
        }
        $orderInfo = [
            'amountDetails' => [
                'totalAmount' => $amount,
                'currency' => $currency,
            ],
            'billTo' => $billTo,
        ];

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid,
            ],
            'orderInformation' => $orderInfo,
            'consumerAuthenticationInformation' => [
                'authenticationType' => '01',
                'referenceId' => $setupResponse['consumerAuthenticationInformation']['referenceId'] ?? null,
                'returnUrl' => 'https://' . $_SERVER['HTTP_HOST'] . '/cybersource/3ds-callback',
            ],
        ];

        if (!empty($setupResponse['consumerAuthenticationInformation']['accessToken'])) {
            $payload['consumerAuthenticationInformation']['accessToken'] =
                $setupResponse['consumerAuthenticationInformation']['accessToken'];
        }

        if ($subscriptionId) {
            $cardCustomerId = $this->getCardCustomerId($subscriptionId, $context);
            $payload['paymentInformation'] = [
                'customer' => [
                    'id' => $cardCustomerId,
                ],
                'paymentInstrument' => [
                    'id' => $subscriptionId,
                ]
            ];
        } else {
            $payload['tokenInformation'] = [
                'transientTokenJwt' => $token,
            ];
            $payload['paymentInformation'] = [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear,
                ],
            ];
        }

        try {
            $response = $this->executeRequest('Post', '/risk/v1/authentications', $payload, 'Payer Authentication', $salesChannelId);
            $responseData = $response['body'];
            $status = $responseData['status'] ?? 'UNKNOWN';
            $authenticationTransactionId =
                $responseData['consumerAuthenticationInformation']['authenticationTransactionId'] ?? null;

            if ($status === 'PENDING_AUTHENTICATION') {
                $acsUrl = $responseData['consumerAuthenticationInformation']['acsUrl'] ?? null;
                $pareq = $responseData['consumerAuthenticationInformation']['pareq'] ?? null;
                $accessToken = $responseData['consumerAuthenticationInformation']['accessToken'] ?? null;
                $cardType = $responseData['paymentInformation']['card']['type'] ?? null;

                if ($acsUrl && $pareq && $accessToken) {
                    return new JsonResponse([
                        'success' => true,
                        'action' => '3ds',
                        'authenticationTransactionId' => $authenticationTransactionId,
                        'acsUrl' => $acsUrl,
                        'stepUpUrl' => $responseData['consumerAuthenticationInformation']['stepUpUrl'] ?? null,
                        'pareq' => $pareq,
                        'uniqid' => $uniqid,
                        'accessToken' => $accessToken,
                        'cardType' => $cardType,
                        'orderInfo' => $orderInfo,
                        'transientTokenJwt' => $token,
                        'subscriptionId' => $subscriptionId,
                        'expirationMonth' => $expirationMonth,
                        'expirationYear' => $expirationYear,
                        'saveCard' => $saveCard,
                        'customerId' => $customerId,
                    ]);
                }
                return new JsonResponse([
                    'success' => false,
                    'action' => 'notify',
                    'message' => '3DS authentication required, but necessary information is missing.',
                ]);
            }

            if ($status === 'AUTHENTICATION_SUCCESSFUL') {
                return $this->completePayment(
                    $context,
                    $responseData,
                    $saveCard,
                    $uniqid,
                    $token,
                    $subscriptionId,
                    $expirationMonth,
                    $expirationYear,
                    $orderInfo,
                    $customerId,
                    $salesChannelId
                );
            }

            return new JsonResponse([
                'success' => false,
                'action' => 'notify',
                'message' => 'Payer authentication failed: ' . $status,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => 'Payer authentication failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Complete a payment.
     */
    private function completePayment(
        SalesChannelContext $context,
        array $authResponse,
        bool $saveCard,
        string $uniqid,
        ?string $transientTokenJwt,
        ?string $subscriptionId,
        ?string $expirationMonth,
        ?string $expirationYear,
        array $orderInfo,
        ?string $customerId,
        ?string $salesChannelId = null
    ): JsonResponse {
        $capture = $this->configurationService->getTransactionType() === 'auth_capture';
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid,
            ],
            'processingInformation' => [
                'capture' => $capture,
            ],
            'orderInformation' => $orderInfo,
        ];

        if ($saveCard) {
            $payload['processingInformation']['actionList'] = ['TOKEN_CREATE'];
            $payload['processingInformation']['actionTokenTypes'] = [
                'customer',
                'instrumentIdentifier',
                'paymentInstrument',
            ];
        }

        if (
            $this->configurationService->isThreeDSEnabled($salesChannelId) &&
            !empty($authResponse['consumerAuthenticationInformation'])
        ) {
            $consumerAuthInfo = [];
            if (isset($authResponse['consumerAuthenticationInformation']['cavv'])) {
                $consumerAuthInfo['cavv'] = $authResponse['consumerAuthenticationInformation']['cavv'];
            }
            if (isset($authResponse['consumerAuthenticationInformation']['xid'])) {
                $consumerAuthInfo['xid'] = $authResponse['consumerAuthenticationInformation']['xid'];
            }
            if (!empty($consumerAuthInfo)) {
                $payload['consumerAuthenticationInformation'] = $consumerAuthInfo;
            }
        }

        if ($subscriptionId) {
            $cardCustomerId = $this->getCardCustomerId($subscriptionId, $context, $customerId);
            $payload['paymentInformation'] = [
                'customer' => [
                    'id' => $cardCustomerId,
                ],
                'paymentInstrument' => [
                    'id' => $subscriptionId,
                ]
            ];
            $payload['processingInformation']['commerceIndicator'] = 'internet';
        } else {
            if (!$transientTokenJwt) {
                $this->logger->error('transientTokenJwt is missing in completePayment', [
                    'authResponse' => $authResponse,
                    'subscriptionId' => $subscriptionId,
                ]);
                return new JsonResponse([
                    'success' => false,
                    'action' => 'notify',
                    'message' => 'Payment failed: Missing transientTokenJwt.',
                ], 400);
            }

            $payload['tokenInformation'] = [
                'transientTokenJwt' => $transientTokenJwt,
            ];
            $payload['paymentInformation'] = [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear,
                ],
            ];
        }

        try {
            $response = $this->executeRequest('Post', '/pts/v2/payments', $payload, 'Payment', $salesChannelId);
            $responseData = $response['body'];
            $status = $responseData['status'] ?? 'UNKNOWN';
            $transactionId = $responseData['id'] ?? null;
            $paymentInstrumentId = null;
            if ($saveCard && $transactionId && $status === 'AUTHORIZED') {
                $instrumentIdentifierId = $responseData['tokenInformation']['instrumentIdentifier']['id'] ?? null;
                $cardType = $responseData['paymentInformation']['card']['type'] ?? null;
                if ($instrumentIdentifierId) {
                    $paymentInstrumentId = $this->saveCard(
                        $context,
                        $instrumentIdentifierId,
                        $expirationMonth,
                        $expirationYear,
                        $orderInfo,
                        $cardType,
                        $customerId,
                        $salesChannelId
                    );
                    if (!$paymentInstrumentId) {
                        $this->logger->warning('Failed to save card', [
                            'instrumentIdentifierId' => $instrumentIdentifierId,
                        ]);
                    }
                }
            }

            $paymentData = [
                'cybersource_transaction_id' => $transactionId,
                'cybersource_payment_status' => $status,
                'cybersource_payment_uniqid' => $uniqid,
                'payment_id' => $responseData['clientReferenceInformation']['code'] ?? null,
                'card_category' => $responseData['paymentInformation']['scheme'] ?? null,
                'payment_method_type' => $responseData['paymentInformation']['tokenizedCard']['type'] ?? null,
                'expiry_month' => $responseData['paymentInformation']['card']['expirationMonth'] ??
                    $expirationMonth,
                'expiry_year' => $responseData['paymentInformation']['card']['expirationYear'] ??
                    $expirationYear,
                'card_last_4' => isset($responseData['paymentInformation']['card']['number']) ?
                    substr($responseData['paymentInformation']['card']['number'], -4) : null,
                'gateway_authorization_code' => $responseData['processorInformation']['approvalCode'] ?? null,
                'gateway_token' => $responseData['tokenInformation']['paymentInstrument']['id'] ?? null,
                'gateway_reference' => $responseData['processorInformation']['transactionId'] ?? null,
                'uniqid' => $uniqid,
                'amount' => $orderInfo['amountDetails']['totalAmount'] ?? null,
                'currency' => $orderInfo['amountDetails']['currency'] ?? null,
                'statusCode' => $response['statusCode'],
            ];
            if ($subscriptionId || $saveCard) {
                $savedCards = $this->getSavedCards($context, $customerId, $salesChannelId);
                $savedCard = null;
                foreach ($savedCards['cards'] as $card) {
                    if ($card['id'] === $subscriptionId || $card['id'] === $paymentInstrumentId) {
                        $savedCard = $card;
                        break;
                    }
                }

                if (!$paymentData['gateway_token']) {
                    $paymentData['gateway_token'] = $savedCard['id'] ?? null;
                }
                $paymentData['card_last_4'] = $savedCard ?
                    substr($savedCard['cardNumber'], -4) :
                    $paymentData['card_last_4'];
                $paymentData['expiry_year'] = $savedCard['expirationYear'] ?? $paymentData['expiry_year'];
                $paymentData['expiry_month'] = $savedCard['expirationMonth'] ?? $paymentData['expiry_month'];
            }
            if (!$paymentData['card_last_4'] && $transientTokenJwt) {
                $transactionDetails = $this->retrieveTransientToken($transientTokenJwt, [], $salesChannelId);
                if (
                    $transactionDetails['statusCode'] === 200 &&
                    isset($transactionDetails['body']['paymentInformation']['card']['number'])
                ) {
                    $paymentData['card_last_4'] =
                        substr($transactionDetails['body']['paymentInformation']['card']['number'], -4);
                }
            }
            if (!$paymentData['card_last_4']) {
                for ($i = 0; $i < 3; $i++) {
                    if ($i > 0) {
                        sleep(2); // wait for 2 second before retrying
                    }
                    $transactionDetails = $this->retrieveTransaction($transactionId, [], $salesChannelId);
                    if (
                        $transactionDetails['statusCode'] === 200 &&
                        isset($transactionDetails['body']['paymentInformation']['card']['suffix'])
                    ) {
                        $paymentData['card_last_4'] =
                            substr($transactionDetails['body']['paymentInformation']['card']['suffix'], -4);
                        break;
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
                        'message' => 'Payment authorized successfully.',
                        'paymentData' => $paymentData,
                    ]);
                case 'PARTIAL_AUTHORIZED':
                    return new JsonResponse([
                        'success' => true,
                        'action' => 'complete',
                        'status' => $status,
                        'transactionId' => $transactionId,
                        'uniqid' => $uniqid,
                        'message' => 'Payment partially authorized. Please contact support.',
                        'paymentData' => $paymentData,
                    ]);
                case 'AUTHORIZED_PENDING_REVIEW':
                    return new JsonResponse([
                        'success' => true,
                        'action' => 'complete',
                        'status' => $status,
                        'transactionId' => $transactionId,
                        'uniqid' => $uniqid,
                        'message' => 'Payment authorized but pending review.',
                        'paymentData' => $paymentData,
                    ]);
                case 'DECLINED':
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'message' => 'Payment declined. Please try a different payment method.',
                        'paymentData' => $paymentData,
                    ]);
                default:
                    return new JsonResponse([
                        'success' => false,
                        'action' => 'notify',
                        'message' => 'Payment failed: ' . ($responseData['message'] ?? $status),
                        'details' => $responseData['details'] ?? [],
                        'paymentData' => $paymentData,
                    ]);
            }
        } catch (\RuntimeException $e) {
            $errorResponse = json_decode($e->getMessage(), true) ?? ['error' => $e->getMessage()];
            $this->logger->error('Payment failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
                'response' => $errorResponse,
            ]);
            return new JsonResponse([
                'error' => 'Payment failed',
                'message' => $errorResponse['message'] ?? $e->getMessage(),
                'details' => $errorResponse['details'] ?? [],
            ], 500);
        }
    }

    /**
     * Get saved cards for a customer.
     */
    public function getSavedCards(SalesChannelContext $context, ?string $customerId = null, ?string $salesChannelId = null): array
    {
        $customer = $context->getCustomer();
        if (!$customer) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $customerId));
            $criteria->addAssociation('defaultBillingAddress');
            $criteria->addAssociation('defaultBillingAddress.country');
            $criteria->addAssociation('defaultBillingAddress.countryState');
            $customer = $this->customerRepository->search($criteria, $context->getContext())->first();
            if (!$customer) {
                return ['cards' => []];
            }
        }
        $customerTokenId = $customer->getCustomFields()['cybersource_customer_token'] ?? null;
        if (!$customerTokenId) {
            $this->logger->warning('No customer token found for customer', [
                'customerId' => $customer->getId(),
                'customFields' => $customer->getCustomFields(),
            ]);
            return ['cards' => []];
        }
        if(!$salesChannelId){
            $salesChannelId = $context->getSalesChannel()->getId();
        }
        try {
            $response = $this->executeRequest(
                'Get',
                "/tms/v2/customers/{$customerTokenId}/payment-instruments",
                [],
                'Get Saved Cards',
                $salesChannelId
            );
            $cards = $response['body']['_embedded']['paymentInstruments'] ?? [];

            $cardsToReturn = array_map(function ($card) use ($customerTokenId) {
                return [
                    'id' => $card['id'],
                    'cardNumber' => $card['_embedded']['instrumentIdentifier']['card']['number'] ?? 'N/A',
                    'expirationMonth' => $card['card']['expirationMonth'] ?? 'N/A',
                    'expirationYear' => $card['card']['expirationYear'] ?? 'N/A',
                    'type' => $card['card']['type'] ?? 'N/A',
                    'billingAddress' => $card['billTo'] ?? [],
                    'customerId' => $customerTokenId,
                    'default' => $card['default'] ?? false,
                    'state' => $card['state'] ?? 'N/A',
                ];
            }, $cards);

            return ['cards' => $cardsToReturn];
        } catch (\RuntimeException $e) {
            return ['cards' => []];
        }
    }

    /**
     * Save a card for a customer.
     */
    public function saveCard(
        SalesChannelContext $context,
        string $paymentToken,
        ?string $expirationMonth,
        ?string $expirationYear,
        array $orderInfo = [],
        ?string $cardType = null,
        ?string $customerId = null,
        ?string $salesChannelId = null
    ): ?string {
        if ($expirationMonth === null || $expirationYear === null) {
            $this->logger->error('Expiration month or year is missing.');
            return null;
        }
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $customerId));
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultBillingAddress.countryState');

        $customer = $this->customerRepository->search($criteria, $context->getContext())->first();
        if (!$customer) {
            $this->logger->warning('No customer found to save card', [
                'salesChannelId' => $context->getSalesChannelId(),
            ]);
            return null;
        }

        $customerTokenId = $customer->getCustomFields()['cybersource_customer_token'] ?? null;
        if (!$customerTokenId) {
            $payload = [
                'customerInformation' => [
                    'email' => $customer->getEmail(),
                ],
            ];
            try {
                $response = $this->executeRequest('Post', '/tms/v2/customers', $payload, 'Create Customer Token', $salesChannelId);
                $customerTokenId = $response['body']['id'];

                if (!$customerTokenId) {
                    throw new \RuntimeException('Failed to create customer token: No customerTokenId returned');
                }

                $this->customerRepository->update([
                    [
                        'id' => $customer->getId(),
                        'customFields' => array_merge(
                            $customer->getCustomFields() ?? [],
                            ['cybersource_customer_token' => $customerTokenId]
                        ),
                    ],
                ], $context->getContext());
            } catch (\RuntimeException $e) {
                $this->logger->error('Failed to create customer token', ['error' => $e->getMessage()]);
                return null;
            }
        }

        $payload = [
            'card' => [
                'expirationMonth' => $expirationMonth,
                'expirationYear' => $expirationYear,
                'type' => $cardType,
            ],
            'billTo' => $orderInfo['billTo'] ?? [],
            'instrumentIdentifier' => [
                'id' => $paymentToken,
            ],
        ];

        try {
            $res = $this->executeRequest(
                'Post',
                "/tms/v2/customers/{$customerTokenId}/payment-instruments",
                $payload,
                'Associate Instrument Identifier',
                $salesChannelId
            );
            $paymentInstrumentId = $res['body']['id'] ?? null;
            $this->customerRepository->update([
                [
                    'id' => $customer->getId(),
                    'customFields' => array_merge(
                        $customer->getCustomFields() ?? [],
                        ['cybersource_payment_token' => $paymentToken]
                    ),
                ],
            ], $context->getContext());
            return $paymentInstrumentId;
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to associate instrument identifier', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Handle 3DS callback.
     */
    public function handle3dsCallback(Request $request, SalesChannelContext $context): Response
    {
        $data = $request->request->get('MD');
        $data = is_string($data) ? json_decode($data, true) : [];
        $salesChannelId = $context->getSalesChannel()->getId();
        $authenticationTransactionId = $data['authenticationTransactionId'] ?? null;
        $orderInfo = $data['orderInfo'] ?? null;
        $uniqid = $data['uniqid'] ?? null;
        $transientTokenJwt = $data['transientTokenJwt'] ?? null;
        $subscriptionId = $data['subscriptionId'] ?? null;
        $expirationMonth = $data['expirationMonth'] ?? null;
        $expirationYear = $data['expirationYear'] ?? null;
        $cardType = $data['cardType'] ?? null;
        $customerId = $data['customerId'] ?? null;
        $saveCard = filter_var($data['saveCard'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . $uniqid,
            ],
            'paymentInformation' => [
                'card' => [
                    'type' => $cardType,
                ],
            ],
            'consumerAuthenticationInformation' => [
                'authenticationTransactionId' => $authenticationTransactionId,
            ],
        ];

        try {
            $response = $this->executeRequest(
                'Post',
                '/risk/v1/authentication-results',
                $payload,
                'Authentication Results',
                $salesChannelId
            );
            $responseData = $response['body'];
            $status = $responseData['status'] ?? 'UNKNOWN';

            $return = $status === 'AUTHENTICATION_SUCCESSFUL'
                ? $this->completePayment(
                    $context,
                    $responseData,
                    $saveCard,
                    $uniqid,
                    $transientTokenJwt,
                    $subscriptionId,
                    $expirationMonth,
                    $expirationYear,
                    $orderInfo,
                    $customerId,
                    $salesChannelId
                )->getContent()
                : [
                    'success' => false,
                    'action' => 'notify',
                    'message' => '3DS authentication failed: ' . $status,
                ];
        } catch (\RuntimeException $e) {
            $return = [
                'success' => false,
                'action' => 'notify',
                'message' => $e->getMessage(),
            ];
        }

        $response = new Response(
            '<!DOCTYPE html><html lang="en"><body><script>window.parent.postMessage({action: "close_frame", data: '
            . json_encode($return)
            . '}, "*");</script></body></html>'
        );
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' *.cardinalcommerce.com");
        $response->headers->set('X-Frame-Options', 'ALLOW-FROM *.cardinalcommerce.com');
        return $response;
    }

    /**
     * Set a payment instrument as the default for a customer.
     */
    public function setDefaultPaymentInstrument(
        string $customerId,
        string $paymentInstrumentId,
        SalesChannelContext $context
    ): bool {
        $payload = [
            'default' => true,
        ];

        try {
            $salesChannelId = $context->getSalesChannel()->getId();
            $response = $this->executeRequest(
                'Patch',
                "/tms/v2/customers/{$customerId}/payment-instruments/{$paymentInstrumentId}",
                $payload,
                'Set Default Payment Instrument',
                $salesChannelId
            );
            return $response['statusCode'] === 200;
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to set default payment instrument', [
                'customerId' => $customerId,
                'paymentInstrumentId' => $paymentInstrumentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete a saved card.
     */
    public function deleteCard(Request $request, SalesChannelContext $context): JsonResponse
    {
        $instrumentId = $request->request->get('instrumentId');
        $customerId = $request->request->get('customerId');
        $salesChannelId = $context->getSalesChannel()->getId();
        if (!$instrumentId || !$customerId) {
            return new JsonResponse(['error' => 'Missing instrumentId or customerId'], 400);
        }
        if (!is_string($customerId)) {
            return new JsonResponse(['error' => 'Customer not found'], 403);
        }
        $cardsResponse = $this->getSavedCards($context, $customerId, $salesChannelId);
        $cards = $cardsResponse['cards'] ?? [];
        $targetCard = null;
        $otherCards = [];

        foreach ($cards as $card) {
            if ($card['id'] === $instrumentId) {
                $targetCard = $card;
            } else {
                $otherCards[] = $card;
            }
        }

        if (!$targetCard) {
            return new JsonResponse(['error' => 'Card not found'], 404);
        }

        if ($targetCard['default'] && !empty($otherCards)) {
            $newDefaultCard = $otherCards[0];
            $success = $this->setDefaultPaymentInstrument($customerId, $newDefaultCard['id'], $context);
            if (!$success) {
                return new JsonResponse(['error' => 'Failed to set new default card'], 500);
            }
        }

        try {
            $response = $this->executeRequest(
                'Delete',
                "/tms/v2/customers/{$customerId}/payment-instruments/{$instrumentId}",
                [],
                'Delete Card',
                $salesChannelId
            );
            return new JsonResponse(['success' => $response['statusCode'] === 204]);
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to delete card', [
                'customerId' => $customerId,
                'instrumentId' => $instrumentId,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Failed to delete card: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Add a new card.
     */
    public function addCard(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $expirationMonth = $data['expirationMonth'] ?? null;
        $expirationYear = $data['expirationYear'] ?? null;
        $billTo = $data['billingAddress'] ?? null;
        $salesChannelId = $context->getSalesChannel()->getId();
        if (!$token || !$expirationMonth || !$expirationYear) {
            $this->logger->error('Missing required fields for adding card', [
                'token' => $token,
                'expirationMonth' => $expirationMonth,
                'expirationYear' => $expirationYear,
            ]);
            return new JsonResponse(['message' => 'Missing token, expirationMonth, or expirationYear'], 400);
        }

        $customer = $context->getCustomer();
        if (!$customer) {
            $this->logger->error('No customer found in context');
            return new JsonResponse(['message' => 'Customer not authenticated'], 403);
        }

        $customerId = $customer->getId();
        $customerNo = $customer->getCustomerNumber();
        $billingAddress = $customer->getActiveBillingAddress();
        if ($billTo) {
            $shortCode = $billTo['state'];
            if (strpos($shortCode, '-') !== false) {
                $shortCode = explode('-', $shortCode);
                if (count($shortCode) > 1) {
                    $billTo['administrativeArea'] = $shortCode[1];
                }
            } elseif ($shortCode) {
                $billTo['administrativeArea'] = $shortCode;
            }
            unset($billTo['state']);
            $billTo['email'] = $customer->getEmail();
        } else {
            $billTo = $this->buildBillTo($customer, $billingAddress);
        }
        $uniqid = uniqid();

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'AddCard-' . $uniqid . '-' . $customerNo,
            ],
            'processingInformation' => [
                'actionList' => ['TOKEN_CREATE'],
                'actionTokenTypes' => ['customer', 'instrumentIdentifier', 'paymentInstrument'],
                'capture' => false,
            ],
            'tokenInformation' => [
                'transientTokenJwt' => $token,
            ],
            'paymentInformation' => [
                'card' => [
                    'expirationMonth' => $expirationMonth,
                    'expirationYear' => $expirationYear,
                ],
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => '0.00',
                    'currency' => $context->getCurrency()->getIsoCode(),
                ],
                'billTo' => $billTo,
            ],
        ];

        try {
            $response = $this->executeRequest('Post', '/pts/v2/payments', $payload, 'Add Card Payment', $salesChannelId);
            $responseData = $response['body'];
            $status = $responseData['status'] ?? 'UNKNOWN';
            $successStatus = [
                'AUTHORIZED',
                'PARTIAL_AUTHORIZED',
                'AUTHORIZED_PENDING_REVIEW',
            ];
            if (!in_array($status, $successStatus)) {
                $this->logger->error('Card authorization failed', ['status' => $status, 'response' => $responseData]);
                return new JsonResponse(['message' => 'Card authorization failed: ' . $status], 400);
            }

            $instrumentIdentifierId = $responseData['tokenInformation']['instrumentIdentifier']['id'] ?? null;
            $cardType = $responseData['paymentInformation']['card']['type'] ?? null;

            if (!$instrumentIdentifierId) {
                $this->logger->error('No instrumentIdentifier returned', ['response' => $responseData]);
                return new JsonResponse(['message' => 'Failed to retrieve instrument identifier'], 500);
            }

            $paymentInstrumentId = $this->saveCard(
                $context,
                $instrumentIdentifierId,
                $expirationMonth,
                $expirationYear,
                ['billTo' => $billTo],
                $cardType,
                $customerId,
                $salesChannelId
            );

            if (!$paymentInstrumentId) {
                $this->logger->error('Failed to save card to TMS', [
                    'instrumentIdentifierId' => $instrumentIdentifierId,
                    'customerId' => $customerId,
                ]);
                return new JsonResponse(['message' => 'Failed to save card'], 500);
            }

            return new JsonResponse(['success' => true, 'message' => 'Card successfully added']);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['message' => 'Failed to add card: ' . $e->getMessage()], 500);
        }
    }

    private function getCardCustomerId(
        mixed $subscriptionId,
        SalesChannelContext $context,
        string $customerId = null
    ): ?string {
        $salesChannelId = $context->getSalesChannel()->getId();
        $cardsResponse = $this->getSavedCards($context, $customerId, $salesChannelId);
        $cards = $cardsResponse['cards'] ?? [];
        $validCard = null;
        foreach ($cards as $card) {
            if ($card['id'] === $subscriptionId && strtolower($card['state']) === 'active') {
                $validCard = $card;
                break;
            }
        }
        if ($validCard) {
            return $validCard['customerId'];
        } else {
            $this->logger->error('No valid payment instrument found for customer', [
                'subscriptionId' => $subscriptionId,
                'cards' => $cards,
            ]);
            return null;
        }
    }

    public function transitionOrderPayment(
        string  $orderId,
        string  $state,
        string  $currentState,
        Context $context,
        bool    $skipTransition = false
    ): array
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('currency');
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();
        if (!$order) {
            throw new \RuntimeException('Order not found for ID: ' . $orderId);
        }

        $transaction = $order->getTransactions()?->first();
        if (!$transaction) {
            throw new \RuntimeException('Transaction not found for order ID: ' . $orderId);
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($customFields);
        if (!$cybersourceTransactionId) {
            throw new \RuntimeException('CyberSource transaction ID not found for order: ' . $orderId);
        }

        $validTransitions = [
            'authorized' => ['paid', 'cancel', 'cancelled'],
            'paid' => ['refund', 'refunded', 'cancel', 'cancelled'],
            'pending_review' => ['paid', 'cancel', 'cancelled'],
            'pre_review' => ['authorized', 'cancel', 'cancelled']
        ];

        $currentStateLower = strtolower($currentState);
        $stateLower = strtolower($state);
        $salesChannelId = $order->getSalesChannelId();
        if (
            !isset($validTransitions[$currentStateLower]) ||
            !in_array($stateLower, $validTransitions[$currentStateLower])
        ) {
            throw new \RuntimeException('Invalid transition from ' . $currentStateLower . ' to ' . $stateLower);
        }

        $statusMapping = [
            'paid' => OrderTransactionStates::STATE_PAID,
            'cancel' => OrderTransactionStates::STATE_CANCELLED,
            'refund' => OrderTransactionStates::STATE_REFUNDED,
            'cancelled' => OrderTransactionStates::STATE_CANCELLED,
            'refunded' => OrderTransactionStates::STATE_REFUNDED,
            'authorized' => OrderTransactionStates::STATE_AUTHORIZED,
        ];

        $newStatus = $statusMapping[$stateLower];
        if (!$skipTransition) {
            $templateVariables = new ArrayStruct([
                'source' => 'CyberSourceService'
            ]);
            $context->addExtension('customPaymentUpdate', $templateVariables);
            switch ($newStatus) {
                case OrderTransactionStates::STATE_PAID:
                    $this->orderTransactionStateHandler->paid($transaction->getId(), $context);
                    break;
                case OrderTransactionStates::STATE_CANCELLED:
                    $this->orderTransactionStateHandler->cancel($transaction->getId(), $context);
                    break;
                case OrderTransactionStates::STATE_REFUNDED:
                    $this->orderTransactionStateHandler->refund($transaction->getId(), $context);
                    break;
                case OrderTransactionStates::STATE_AUTHORIZED:
                    $this->orderTransactionStateHandler->authorize($transaction->getId(), $context);
                    break;
                default:
                    $this->logger->error('Unsupported transition state: ' . $stateLower, ['orderId' => $orderId]);
                    return [
                        'success' => false,
                        'message' => 'Unsupported transition state: ' . $stateLower,
                        'data' => []
                    ];
            }
        }
        $currencyEntity = $order->getCurrency();
        $currency = $currencyEntity instanceof CurrencyEntity ? $currencyEntity->getIsoCode() : 'USD';
        $totalAmount = round($order->getAmountTotal(), 2);

        $uniqId = $this->orderService->getCyberSourceTransactionUniqueId($customFields);
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($uniqId ?? $cybersourceTransactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $totalAmount,
                    'currency' => $currency,
                ],
            ],
        ];

        try {
            $orderInformation = $payload['orderInformation'];
            $response = null;
            $logType = "";
            switch (strtoupper($state)) {
                case 'PAID':
                    $response = $this->executeRequest(
                        'Post',
                        "/pts/v2/payments/{$cybersourceTransactionId}/captures",
                        $payload,
                        'Capture Transition',
                        $salesChannelId
                    );
                    $logType = 'Payment';
                    break;
                case 'CANCEL':
                case 'CANCELLED':
                    $payload['reversalInformation'] = $orderInformation;
                    unset($payload['orderInformation']);
                    $response = $this->executeRequest(
                        'Post',
                        "/pts/v2/payments/{$cybersourceTransactionId}/reversals",
                        $payload,
                        'Void Transition',
                        $salesChannelId
                    );
                    $logType = 'Canceled';
                    break;
                case 'REFUND':
                case 'REFUNDED':
                    $response = $this->executeRequest(
                        'Post',
                        "/pts/v2/payments/{$cybersourceTransactionId}/refunds",
                        $payload,
                        'Refund Transition',
                        $salesChannelId
                    );
                    $logType = 'Refunded';
                    break;
                case 'AUTHORIZED':
                    // No API call needed for authorization in this context
                    $message = 'Order transition to ' . ucfirst($stateLower) . ' successful.';
                    return [
                        'success' => true,
                        'message' => $message,
                        'data' => null
                    ];
                default:
                    throw new \RuntimeException('Unsupported transition state: ' . $state);
            }

            // Merge existing custom fields with response data to fill in missing values
            $responseData = $response['body'];
            $customPaymentDetails = $customFields['cybersource_payment_details']['transactions'][0] ?? [];
            $mergedData = array_merge($responseData, [
                'id' => $cybersourceTransactionId,
                'clientReferenceInformation' => $responseData['clientReferenceInformation'] ??
                    ['code' => $payload['clientReferenceInformation']['code']],
                'paymentInformation' => [
                    'card' => [
                        'type' => $responseData['paymentInformation']['card']['type'] ??
                                $customPaymentDetails['card_category'] ?? null,
                        'brand' => $responseData['paymentInformation']['card']['brand'] ??
                                $customPaymentDetails['payment_method_type'] ?? null,
                        'expirationMonth' => $responseData['paymentInformation']['card']['expirationMonth'] ??
                                $customPaymentDetails['expiry_month'] ?? null,
                        'expirationYear' => $responseData['paymentInformation']['card']['expirationYear'] ??
                                $customPaymentDetails['expiry_year'] ?? null,
                        'number' =>
                            $customPaymentDetails['card_last_4'] ? '****' . $customPaymentDetails['card_last_4'] :
                                ($responseData['paymentInformation']['card']['number'] ?? null),
                    ],
                ],
                'processorInformation' => [
                    'approvalCode' => $responseData['processorInformation']['approvalCode'] ??
                            $customPaymentDetails['gateway_authorization_code'] ?? null,
                    'transactionId' => $responseData['processorInformation']['transactionId'] ??
                            $customPaymentDetails['gateway_reference'] ?? null,
                ],
                'tokenInformation' => [
                    'paymentInstrument' => [
                        'id' => $responseData['tokenInformation']['paymentInstrument']['id'] ??
                                $customPaymentDetails['gateway_token'] ?? null,
                    ],
                ],
                'orderInformation' => $orderInformation
            ]);

            $mergedData['statusCode'] = $response['statusCode'];
            $mergedData['id'] = $response['body']['id'] ?? $cybersourceTransactionId;
            // Log the transaction with merged data
            $this->transactionLogger->logTransaction($logType, $mergedData, $transaction->getId(), $context, $uniqId);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                $message = 'Order transition to ' . ucfirst($stateLower) . ' successful.';
                return [
                    'success' => true,
                    'message' => $message,
                    'data' => $response['body']
                ];
            } else {
                if (!$skipTransition) {
                    $this->orderTransactionStateHandler->process($transaction->getId(), $context);
                }
                throw new \RuntimeException('Transition failed: ' . ($response['body']['message'] ?? 'Unknown error'));
            }
        } catch (\RuntimeException $e) {
            if (!$skipTransition) {
                $this->revertTransactionState($transaction->getId(), $currentState, $context);
            }
            $this->logger->error('Transition failed for order ' . $orderId, [
                'state' => $state,
                'currentState' => $currentState,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Failed to complete the transition to ' . ucfirst($stateLower) . '. Please try again ',
                'data' => []
            ];
        }
    }

    private function revertTransactionState(string $transactionId, string $previousState, Context $context): bool
    {
        try {
            $this->logger->warning(
                "Attempting to revert transaction {$transactionId} to previous state: {$previousState}"
            );

            $transitions = $this->stateMachineRegistry->getAvailableTransitions(
                'order_transaction',
                $transactionId,
                'stateId',
                $context
            );
            $availableTransitions = array_map(static function ($transition) {
                return $transition->getActionName();
            }, $transitions);

            $this->logger->info(
                "Available transitions for transaction {$transactionId}: " . implode(', ', $availableTransitions)
            );

            if (!in_array($previousState, $availableTransitions, true)) {
                $this->logger->error(
                    "Invalid transition to {$previousState} for transaction {$transactionId}. Valid transitions: "
                    . implode(', ', $availableTransitions)
                );
                return false;
            }

            $this->stateMachineRegistry->transition(
                new Transition(
                    'order_transaction',
                    $transactionId,
                    $previousState,
                    'stateId'
                ),
                $context
            );
            $this->logger->info("Successfully reverted transaction {$transactionId} to state {$previousState}");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to revert transaction state for {$transactionId}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Build fingerprint configuration for the storefront.
     */
    public function getFingerprintConfig(SalesChannelContext $context): array
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        $enabled = $this->shouldAttachFingerprint($salesChannelId);
        if (!$enabled) {
            return [
                'enabled' => false,
                'scriptUrl' => null,
                'pixelUrl' => null,
                'iframeUrl' => null,
            ];
        }
        $domain = $this->configurationService->getFingerprintDomain($salesChannelId);
        $orgId = $this->configurationService->getFingerprintOrganizationId($salesChannelId);
        $merchantId = $this->configurationService->getMerchantId($salesChannelId);
        $sessionToken = $this->resolveFingerprintSessionToken();
        if (!$domain || !$orgId || !$merchantId || !$sessionToken) {
            $this->logger->warning('Fingerprint config incomplete; skipping script build', [
                'domain' => $domain,
                'orgId' => $orgId,
                'merchantId' => $merchantId,
                'sessionTokenPresent' => (bool)$sessionToken,
            ]);
            return [
                'enabled' => false,
                'scriptUrl' => null,
                'pixelUrl' => null,
                'iframeUrl' => null,
            ];
        }
        $sessionIdParam = $this->buildFingerprintSessionId($salesChannelId) ?? ($merchantId . $sessionToken);
        $scriptUrl = sprintf('https://%s/fp/tags.js?org_id=%s&session_id=%s', $domain, rawurlencode($orgId), rawurlencode($sessionIdParam));
        $pixelUrl = sprintf('https://%s/fp/clear.png?org_id=%s&session_id=%s', $domain, rawurlencode($orgId), rawurlencode($sessionIdParam));
        $iframeUrl = sprintf('https://%s/fp/tags?org_id=%s&session_id=%s', $domain, rawurlencode($orgId), rawurlencode($sessionIdParam));
        return [
            'enabled' => true,
            'scriptUrl' => $scriptUrl,
            'pixelUrl' => $pixelUrl,
            'iframeUrl' => $iframeUrl,
        ];
    }
}

