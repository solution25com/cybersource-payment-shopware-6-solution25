<?php

namespace CyberSource\Shopware6\Controllers;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use CyberSource\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
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

#[Route(defaults: ['_routeScope' => ['api']])]
class CyberSourceController extends AbstractController
{
    private OrderService $orderService;

    private ConfigurationService $configurationService;
    private CyberSourceFactory $cyberSourceFactory;
    private OrderTransactionStateHandler $orderTransactionStateHandler;
    private TranslatorInterface $translator;
    private CyberSourceApiClient $apiClient;

    public function __construct(
        OrderService $orderService,
        ConfigurationService $configurationService,
        CyberSourceFactory $cyberSourceFactory,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        TranslatorInterface $translator,
        CyberSourceApiClient $apiClient,
    ) {
        $this->orderService = $orderService;
        $this->configurationService = $configurationService;
        $this->cyberSourceFactory = $cyberSourceFactory;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->translator = $translator;
        $this->apiClient = $apiClient;
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
    ): JsonResponse {
        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }

        $paymentStatus = $this->orderService->getPaymentStatus($orderTransaction);
        $customField = $orderTransaction->getCustomFields();
        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($customField);
        $cybersourceUniqid = $this->orderService->getCyberSourceTransactionUniqueId($customField);
        $cybersourcePaymentId = $this->orderService->getCyberSourceTransactionPaymentId($customField);
        if ($cybersourceTransactionId == null) {
            return new JsonResponse(["error" => "No CyberSource transaction ID found"], 404);
        }
        $orderData = $orderTransaction->getOrder();
        if (!$orderData instanceof OrderEntity) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }
        $shopwareAmount = $orderData->getAmountTotal();
        $shopwareOrderTransactionId = $orderTransaction->getId();
        // Initialize response
        $response = [
            'cybersource_transaction_id' => $cybersourceTransactionId,
            'payment_status' => $paymentStatus,
            'amount' => $shopwareAmount,
            'updated' => false,
            'cybersource_status' => null
        ];

        try {
            $payload = [
                'clientReferenceInformation' => [
                    'code' => $cybersourceUniqid
                ]
            ];
            $csTransaction = $this->apiClient->retrieveTransaction($cybersourcePaymentId, $payload);
            // Extract CyberSource status and amount
            $csStatus = $csTransaction['status'] ?? null;
            $csAmount = (float) ($csTransaction['orderInformation']['amountDetails']['totalAmount'] ?? $shopwareAmount);
            $response['cybersource_status'] = $csStatus;

            // Map CyberSource status to Shopware OrderTransactionStates
            $statusMapping = [
                'AUTHORIZED' => OrderTransactionStates::STATE_AUTHORIZED,
                'SETTLED' => OrderTransactionStates::STATE_PAID,
                'REFUNDED' => OrderTransactionStates::STATE_REFUNDED,
                'PARTIALLY_REFUNDED' => OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
                'DECLINED' => OrderTransactionStates::STATE_FAILED,
                'CANCELLED' => OrderTransactionStates::STATE_CANCELLED
            ];

            $newStatus = $statusMapping[$csStatus] ?? null;

            // Check for discrepancies
            $statusMismatch = $newStatus && $newStatus !== $paymentStatus;
            $amountMismatch = abs($csAmount - $shopwareAmount) > 0.01; // Allow small floating-point differences

            if ($statusMismatch || $amountMismatch) {
                // Update transaction state if status mismatch
                if ($statusMismatch) {
                    switch ($newStatus) {
                        case OrderTransactionStates::STATE_AUTHORIZED:
                            $this->orderTransactionStateHandler->authorize($shopwareOrderTransactionId, $context);
                            break;
                        case OrderTransactionStates::STATE_PAID:
                            $this->orderTransactionStateHandler->paid($shopwareOrderTransactionId, $context);
                            break;
                        case OrderTransactionStates::STATE_REFUNDED:
                            $this->orderTransactionStateHandler->refund($shopwareOrderTransactionId, $context);
                            break;
                        case OrderTransactionStates::STATE_PARTIALLY_REFUNDED:
                            $this->orderTransactionStateHandler->refundPartially($shopwareOrderTransactionId, $context);
                            break;
                        case OrderTransactionStates::STATE_FAILED:
                            $this->orderTransactionStateHandler->fail($shopwareOrderTransactionId, $context);
                            break;
                        case OrderTransactionStates::STATE_CANCELLED:
                            $this->orderTransactionStateHandler->cancel($shopwareOrderTransactionId, $context);
                            break;
                    }
                }

                // Update custom fields if amount or status changed
                $customFields = $orderTransaction->getCustomFields() ?? [];
                $customFields['cybersource_payment_details'] = array_merge(
                    $customFields['cybersource_payment_details'] ?? [],
                    [
                        'transaction_id' => $cybersourceTransactionId,
                        'updated_total' => $csAmount,
                        'cybersource_status' => $csStatus,
                        'last_updated' => (new \DateTime())->format('Y-m-d H:i:s')
                    ]
                );

                $this->orderService->update([
                    [
                        'id' => $shopwareOrderTransactionId,
                        'customFields' => $customFields
                    ]
                ], $context);

                // Update response with new status and amount
                $response['payment_status'] = $newStatus ?? $paymentStatus;
                $response['amount'] = $csAmount;
                $response['updated'] = true;
            }
        } catch (\Exception $e) {
            // Log the error but continue with Shopware data
            error_log("CyberSource API error for transaction $cybersourceTransactionId: " . $e->getMessage());
        }

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
    ): JsonResponse {
        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
        $orderEntity = $orderTransaction->getOrder();
        if (!$orderEntity instanceof OrderEntity) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }
        $currencyEntity = $orderEntity->getCurrency();
        if (!$currencyEntity instanceof CurrencyEntity) {
            throw new \RuntimeException('Currency not found for order.');
        }
        $currency = $currencyEntity->getIsoCode();
        $totalOrderAmount = $orderEntity->getAmountTotal();
        $environmentUrl = $this->configurationService->getBaseUrl();
        $requestSignature = $this->configurationService->getSignatureContract();
        $shopwareOrderTransactionId = $orderTransaction->getId();

        $cyberSource = $this->cyberSourceFactory->createCyberSource(
            $environmentUrl,
            $requestSignature
        );
        $lineItems = $orderEntity->getLineItems();
        if ($lineItems === null) {
            $orderLineItemsData = [];
        } else {
            $orderLineItemsData = $this->orderService->transformLineItems($lineItems);
        }
        $clientReference = $this->orderService->getClientReference($orderEntity);
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
    ): JsonResponse {
        $rawRequestBody = $request->getContent();
        if (!json_validate($rawRequestBody)) {
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
        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
        $orderEntity = $orderTransaction->getOrder();
        if (!$orderEntity instanceof OrderEntity) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }
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
        $currency = $currencyEntity instanceof CurrencyEntity ? $currencyEntity->getShortName() : 'USD';

        $environmentUrl = $this->configurationService->getBaseUrl();
        $requestSignature = $this->configurationService->getSignatureContract();
        $shopwareOrderTransactionId = $orderTransaction->getId();

        $cyberSource = $this->cyberSourceFactory->createCyberSource(
            $environmentUrl,
            $requestSignature
        );
        if ($requestLineItems !== null) {
            $lineItemsEncoded = json_encode($requestLineItems);
            if ($lineItemsEncoded === false) {
                throw new \RuntimeException('Failed to encode line items to JSON: ' . json_last_error_msg());
            }
            $orderLineItemsData = json_decode(
                $lineItemsEncoded,
                true
            );
        } else {
            $lineItems = $orderEntity->getLineItems();
            if ($lineItems === null) {
                $orderLineItemsData = [];
            } else {
                $orderLineItemsData = $this->orderService->transformLineItems($lineItems);
            }
        }

        $clientReference = $this->orderService->getClientReference($orderEntity);
        $refundAmount = $newTotalAmount < $totalOrderAmount ? $totalOrderAmount - $newTotalAmount : $totalOrderAmount;
        $refundPaymentRequestData = [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $refundAmount,
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
            $transactionId = $this->orderService->getCyberSourceTransactionId($orderTransaction->getCustomFields());
            $this->orderService->update([
                [
                    'id' => $shopwareOrderTransactionId,
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

    public function canRefund(OrderTransactionEntity $orderTransaction): bool
    {
        $paymentStatus = $this->orderService->getPaymentStatus($orderTransaction);
        return in_array($paymentStatus, [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_PARTIALLY_REFUNDED
        ]);
    }
}
