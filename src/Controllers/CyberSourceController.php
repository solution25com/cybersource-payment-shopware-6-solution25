<?php

namespace CyberSource\Shopware6\Controllers;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use CyberSource\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use CyberSource\Shopware6\Exceptions\APIException;
use Shopware\Core\Checkout\Payment\PaymentException;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use CyberSource\Shopware6\Exceptions\OrderRefundPaymentStateException;
use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;
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
        if ($cybersourceTransactionId == null) {
            return new JsonResponse(["error" => "No CyberSource transaction ID found"], 404);
        }
        $orderData = $orderTransaction->getOrder();
        if (!$orderData instanceof OrderEntity) {
            return new JsonResponse(['error' => 'Order not found'], 404);
        }
        $shopwareAmount = $orderData->getAmountTotal();
        $response = [
            'cybersource_transaction_id' => $cybersourceTransactionId,
            'payment_status' => $paymentStatus,
            'amount' => $shopwareAmount,
            'updated' => false,
            'cybersource_status' => null
        ];
        return new JsonResponse($response);
    }
    #[Route(
        path: "/api/cybersource/getCybersourceTransactionId/{orderId}",
        name: "api.cybersource.getCybersourceTransactionId",
        methods: ["GET"],
        defaults: ["_acl" => ["order.viewer"]]
    )]
    public function getCybersourceTransactionId(
        string $orderId,
        Context $context
    ): JsonResponse {
        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            return new JsonResponse(["cyberSourceTransactionId" => null]);
        }
        $customField = $orderTransaction->getCustomFields();
        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($customField);
        return new JsonResponse(["cyberSourceTransactionId" => $cybersourceTransactionId]);
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
        $salesChannelId = $orderEntity->getSalesChannelId();
        $currency = $currencyEntity->getIsoCode();
        $totalOrderAmount = $orderEntity->getAmountTotal();
        $environmentUrl = $this->configurationService->getBaseUrl($salesChannelId);
        $requestSignature = $this->configurationService->getSignatureContract($salesChannelId);
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
        $salesChannelId =  $orderEntity->getSalesChannelId();
        $environmentUrl = $this->configurationService->getBaseUrl($salesChannelId);
        $requestSignature = $this->configurationService->getSignatureContract($salesChannelId);
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

    #[Route(
        path: "/api/cybersource/order/{orderId}/transition",
        name: "api.cybersource.order_transition_payment",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function transitionOrderPayment(
        string $orderId,
        Request $request,
        Context $context
    ): JsonResponse {
        $transaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);

        if (!$transaction) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No transaction found for this order. Please contact support.',
            ], 400);
        }

        $data = json_decode($request->getContent(), true);
        $state = strtolower($data['targetState'] ?? '');
        $currentState = strtolower($data['currentState'] ?? '');

        try {
            $response = $this->apiClient->transitionOrderPayment($orderId, $state, $currentState, $context);

            if ($response['success']) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $response['message'],
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => $response['message'],
                ], 500);
            }
        } catch (\Exception $e) {
            error_log("Error in transitionOrderPayment for order $orderId: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'message' => 'An error occurred during the transition. Please try again or contact support.',
            ], 500);
        }
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
