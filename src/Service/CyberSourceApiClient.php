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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    private TransactionLogger $transactionLogger;
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
        TransactionLogger $transactionLogger
    ) {
        $this->configurationService = $configurationService;
        $this->cartService = $cartService;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->transactionLogger = $transactionLogger;
        $this->signer = $configurationService->getSignatureContract();
        $this->baseUrl = $configurationService->getBaseUrl()->value;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
        ]);
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
        string $logContext = 'API Request'
    ): array
    {
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

    function formatCyberSourceError(string|array $response): string
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
        return $this->executeRequest('Post', '/kms/egress/v2/keys-sym', $payload, 'Create Key');
    }

    /**
     * Create a webhook.
     */
    public function createWebhook(array $payload, ?string $salesChannelId = null): array
    {
        return $this->executeRequest('Post', '/notification-subscriptions/v2/webhooks', $payload, 'Create Webhook');
    }

    /**
     * Read a webhook.
     */
    public function readWebhook(string $webhookId): array
    {
        return $this->executeRequest('Get', "/notification-subscriptions/v2/webhooks/{$webhookId}", [], 'Read Webhook');
    }

    /**
     * Read a webhook.
     */
    public function readAllWebhook(string $orgId): array
    {
        return $this->executeRequest('Get', "/notification-subscriptions/v2/webhooks?organizationId={$orgId}", [], 'Read Webhooks');
    }

    /**
     * Update a webhook.
     */
    public function updateWebhook(string $webhookId, array $payload): array
    {
        return $this->executeRequest('put', "/notification-subscriptions/v2/webhooks/{$webhookId}/status", $payload, 'Update Webhook');
    }

    /**
     * Delete a webhook.
     */
    public function deleteWebhook(string $webhookId): array
    {
        return $this->executeRequest('Delete', "/notification-subscriptions/v2/webhooks/{$webhookId}", [], 'Delete Webhook');
    }

    /**
     * Retrieve transaction details by transaction ID.
     */
    public function retrieveTransaction(string $cybersourcePaymentId, array $payload): array
    {
        try {
            return $this->executeRequest('POST', "/pts/v2/refresh-payment-status/{$cybersourcePaymentId}", [], 'Retrieve Transaction');
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
    public function capturePayment(string $transactionId, array $payload, string $orderTransactionId, Context $context): array
    {
        $response = $this->executeRequest('Post', "/pts/v2/payments/{$transactionId}/captures", $payload, 'Capture Payment');
        $responseData = $response['body'];
        $this->transactionLogger->logTransaction('Capture', $responseData, $orderTransactionId, $context);
        return $response;
    }

    /**
     * Void a payment.
     */
    public function voidPayment(string $transactionId, array $payload, string $orderTransactionId, Context $context): array
    {
        $response = $this->executeRequest('Post', "/pts/v2/payments/{$transactionId}/voids", $payload, 'Void Payment');
        $responseData = $response['body'];
        $this->transactionLogger->logTransaction('Void', $responseData, $orderTransactionId, $context);
        return $response;
    }

    /**
     * Refund a payment.
     */
    public function refundPayment(string $transactionId, array $payload, string $orderTransactionId, Context $context): array
    {
        $response = $this->executeRequest('Post', "/pts/v2/payments/{$transactionId}/refunds", $payload, 'Refund Payment');
        $responseData = $response['body'];
        $this->transactionLogger->logTransaction('Refund', $responseData, $orderTransactionId, $context);
        return $response;
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
    public function getCaptureContext(): JsonResponse
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
            $response = $this->executeRequest('Post', $endpoint, $payload, 'Get Capture Context');
            return new JsonResponse(['captureContext' => $response['body']]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
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
            $amount = (string)$order->getPrice()->getTotalPrice();
            $currency = $order->getCurrency();
            $currency = $currency instanceof CurrencyEntity ? $currency->getIsoCode() : $context->getCurrency()->getIsoCode();
        } else {
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $amount = (string)$cart->getPrice()->getTotalPrice();
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

        if (!$this->configurationService->isThreeDSEnabled()) {
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
                $customerId
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
            $response = $this->executeRequest('Post', '/risk/v1/authentication-setups', $setupPayload, 'Authentication Setup');
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
        $amount = (string)$cart->getPrice()->getTotalPrice();
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
            $payload['consumerAuthenticationInformation']['accessToken'] = $setupResponse['consumerAuthenticationInformation']['accessToken'];
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
            $response = $this->executeRequest('Post', '/risk/v1/authentications', $payload, 'Payer Authentication');
            $responseData = $response['body'];
            $status = $responseData['status'] ?? 'UNKNOWN';
            $authenticationTransactionId = $responseData['consumerAuthenticationInformation']['authenticationTransactionId'] ?? null;

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
                    $customerId
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
        array               $authResponse,
        bool                $saveCard,
        string              $uniqid,
        ?string             $transientTokenJwt,
        ?string             $subscriptionId,
        ?string             $expirationMonth,
        ?string             $expirationYear,
        array               $orderInfo,
        ?string             $customerId
    ): JsonResponse
    {
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

        if ($this->configurationService->isThreeDSEnabled() && !empty($authResponse['consumerAuthenticationInformation'])) {
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
            $response = $this->executeRequest('Post', '/pts/v2/payments', $payload, 'Payment');
            $responseData = $response['body'];
            $status = $responseData['status'] ?? 'UNKNOWN';
            $transactionId = $responseData['id'] ?? null;

            if ($saveCard && $transactionId && $status === 'AUTHORIZED') {
                $instrumentIdentifierId = $responseData['tokenInformation']['instrumentIdentifier']['id'] ?? null;
                $cardType = $responseData['paymentInformation']['card']['type'] ?? null;
                if ($instrumentIdentifierId) {
                    $saveSuccess = $this->saveCard(
                        $context,
                        $instrumentIdentifierId,
                        $expirationMonth,
                        $expirationYear,
                        $orderInfo,
                        $cardType,
                        $customerId
                    );
                    if (!$saveSuccess) {
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
                'card_category' => $responseData['paymentInformation']['card']['type'] ?? null,
                'payment_method_type' => $responseData['paymentInformation']['card']['brand'] ?? null,
                'expiry_month' => $responseData['paymentInformation']['card']['expirationMonth'] ?? $expirationMonth,
                'expiry_year' => $responseData['paymentInformation']['card']['expirationYear'] ?? $expirationYear,
                'card_last_4' => isset($responseData['paymentInformation']['card']['number']) ? substr($responseData['paymentInformation']['card']['number'], -4) : null,
                'gateway_authorization_code' => $responseData['processorInformation']['authorizationCode'] ?? null,
                'gateway_token' => $responseData['tokenInformation']['paymentInstrument']['id'] ?? null,
                'gateway_reference' => $responseData['processorInformation']['transactionId'] ?? null,
            ];
            if ($subscriptionId || $saveCard) {
                $savedCards = $this->getSavedCards($context, $customerId);
                $savedCard = null;
                foreach ($savedCards['cards'] as $card) {
                    if ($card['id'] === $subscriptionId) {
                        $savedCard = $card;
                        break;
                    }
                }
                $paymentData['card_last_4'] = $savedCard ? substr($savedCard['cardNumber'], -4) : null;
                $paymentData['expiry_year'] = $savedCard['expirationYear'] ?? null;
                $paymentData['expiry_month'] = $savedCard['expirationMonth'] ?? null;
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
    public function getSavedCards(SalesChannelContext $context, ?string $customerId = null): array
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

        try {
            $response = $this->executeRequest(
                'Get',
                "/tms/v2/customers/{$customerTokenId}/payment-instruments",
                [],
                'Get Saved Cards'
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
        string              $paymentToken,
        ?string             $expirationMonth,
        ?string             $expirationYear,
        array               $orderInfo = [],
        ?string             $cardType = null,
        ?string             $customerId = null
    ): bool
    {
        if ($expirationMonth === null || $expirationYear === null) {
            $this->logger->error('Expiration month or year is missing.');
            return false;
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
            return false;
        }

        $customerTokenId = $customer->getCustomFields()['cybersource_customer_token'] ?? null;
        if (!$customerTokenId) {
            $payload = [
                'customerInformation' => [
                    'email' => $customer->getEmail(),
                ],
            ];
            try {
                $response = $this->executeRequest('Post', '/tms/v2/customers', $payload, 'Create Customer Token');
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
                return false;
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
            $this->executeRequest(
                'Post',
                "/tms/v2/customers/{$customerTokenId}/payment-instruments",
                $payload,
                'Associate Instrument Identifier'
            );
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
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to associate instrument identifier', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Handle 3DS callback.
     */
    public function handle3dsCallback(Request $request, SalesChannelContext $context): Response
    {
        $data = $request->request->get('MD');
        $data = is_string($data) ? json_decode($data, true) : [];

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
            $response = $this->executeRequest('Post', '/risk/v1/authentication-results', $payload, 'Authentication Results');
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
                    $customerId
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
            '<html><body><script>window.parent.postMessage({action: "close_frame", data: ' . json_encode($return) . '}, "*");</script></body></html>'
        );
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' *.cardinalcommerce.com");
        $response->headers->set('X-Frame-Options', 'ALLOW-FROM *.cardinalcommerce.com');
        return $response;
    }

    /**
     * Set a payment instrument as the default for a customer.
     */
    public function setDefaultPaymentInstrument(string $customerId, string $paymentInstrumentId, SalesChannelContext $context): bool
    {
        $payload = [
            'default' => true,
        ];

        try {
            $response = $this->executeRequest(
                'Patch',
                "/tms/v2/customers/{$customerId}/payment-instruments/{$paymentInstrumentId}",
                $payload,
                'Set Default Payment Instrument'
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
        if (!$instrumentId || !$customerId) {
            return new JsonResponse(['error' => 'Missing instrumentId or customerId'], 400);
        }
        if (!is_string($customerId)) {
            return new JsonResponse(['error' => 'Customer not found'], 403);
        }
        $cardsResponse = $this->getSavedCards($context, $customerId);
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
                'Delete Card'
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
        } else {
            $billTo = $this->buildBillTo($customer, $billingAddress);
        }
        $uniqid = uniqid();

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'AddCard-' . $uniqid,
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
            $response = $this->executeRequest('Post', '/pts/v2/payments', $payload, 'Add Card Payment');
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

            $saveSuccess = $this->saveCard(
                $context,
                $instrumentIdentifierId,
                $expirationMonth,
                $expirationYear,
                ['billTo' => $billTo],
                $cardType,
                $customerId
            );

            if (!$saveSuccess) {
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

    private function getCardCustomerId(mixed $subscriptionId, SalesChannelContext $context, string $customerId = null): ?string
    {
        $cardsResponse = $this->getSavedCards($context, $customerId);
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
}