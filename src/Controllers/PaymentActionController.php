<?php

namespace CyberSource\Shopware6\Controllers;

use CyberSource\Shopware6\Exceptions\OrderRefundPaymentStateException;
use CyberSource\Shopware6\Service\CyberSourceApiClient;
use CyberSource\Shopware6\Service\OrderService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Checkout\Payment\PaymentException;
use CyberSource\Shopware6\Exceptions\APIException;
use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
class PaymentActionController extends AbstractController
{
    private OrderService $orderService;
    private CyberSourceApiClient $apiClient;
    private OrderTransactionStateHandler $orderTransactionStateHandler;
    private TranslatorInterface $translator;

    public function __construct(
        OrderService                 $orderService,
        CyberSourceApiClient         $apiClient,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        TranslatorInterface          $translator
    )
    {
        $this->orderService = $orderService;
        $this->apiClient = $apiClient;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->translator = $translator;
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}/capture",
        name: "api.cybersource.order_capture",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function capture(string $orderId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = (float)($data['amount'] ?? 0.0);

        if ($amount <= 0) {
            throw PaymentException::capturePreparedException(
                $orderId,
                $this->translator->trans('cybersource_shopware6.exception.CYBERSOURCE_INVALID_AMOUNT: Invalid amount specified for capture.')
            );
        }

        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'),
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

        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($orderTransaction->getCustomFields());
        if (!$cybersourceTransactionId) {
            return new JsonResponse(['error' => 'No CyberSource transaction ID found'], 404);
        }

        $shopwareOrderTransactionId = $orderTransaction->getId();
        $currency = $currencyEntity->getIsoCode();
        $uniqueId = $this->orderService->getCyberSourceTransactionUniqueId($orderTransaction->getCustomFields());

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($uniqueId ?? $cybersourceTransactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $amount,
                    'currency' => $currency
                ]
            ]
        ];

        try {
            $response = $this->apiClient->processPaymentAction('CAPTURE', $cybersourceTransactionId, $payload, $orderId, $context);
            if ($response['status'] === 'success') {
                $templateVariables = new ArrayStruct([
                    'source' => 'CyberSourceService'
                ]);
                $context->addExtension('customPaymentUpdate', $templateVariables);
                $this->orderTransactionStateHandler->paid($shopwareOrderTransactionId, $context);
            }
            return new JsonResponse($response);
        } catch (\Exception $exception) {
            throw new APIException($cybersourceTransactionId, 'API_ERROR', $exception->getMessage());
        }
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}/void",
        name: "api.cybersource.order_void",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function void(string $orderId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = (float)($data['amount'] ?? 0.0);

        if ($amount <= 0) {
            throw PaymentException::asyncProcessInterrupted(
                $orderId,
                $this->translator->trans('cybersource_shopware6.exception.CYBERSOURCE_AMOUNT_INCORRECT')
            );
        }

        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'),
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

        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($orderTransaction->getCustomFields());
        if (!$cybersourceTransactionId) {
            return new JsonResponse(['error' => 'No CyberSource transaction ID found'], 404);
        }

        $shopwareOrderTransactionId = $orderTransaction->getId();
        $currency = $currencyEntity->getIsoCode();
        $uniqueId = $this->orderService->getCyberSourceTransactionUniqueId($orderTransaction->getCustomFields());

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($uniqueId ?? $cybersourceTransactionId),
            ],
            'reversalInformation' => [
                'amountDetails' => [
                    'totalAmount' => $amount,
                    'currency' => $currency
                ]
            ]
        ];

        try {
            $response = $this->apiClient->processPaymentAction('VOID', $cybersourceTransactionId, $payload, $orderId, $context);
            if ($response['status'] === 'success') {
                $templateVariables = new ArrayStruct([
                    'source' => 'CyberSourceService'
                ]);
                $context->addExtension('customPaymentUpdate', $templateVariables);
                $this->orderTransactionStateHandler->cancel($shopwareOrderTransactionId, $context);
            }
            return new JsonResponse($response);
        } catch (\Exception $exception) {
            throw new APIException($cybersourceTransactionId, 'API_ERROR', $exception->getMessage());
        }
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}/refund",
        name: "api.cybersource.order_refund",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function refund(string $orderId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = (float)($data['amount'] ?? 0.0);

        if ($amount <= 0) {
            throw PaymentException::refundInterrupted(
                $orderId,
                $this->translator->trans('cybersource_shopware6.exception.CYBERSOURCE_AMOUNT_INCORRECT')
            );
        }

        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'),
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

        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($orderTransaction->getCustomFields());
        if (!$cybersourceTransactionId) {
            return new JsonResponse(['error' => 'No CyberSource transaction ID found'], 404);
        }

        $shopwareOrderTransactionId = $orderTransaction->getId();
        $currency = $currencyEntity->getIsoCode();
        $uniqueId = $this->orderService->getCyberSourceTransactionUniqueId($orderTransaction->getCustomFields());

        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($uniqueId ?? $cybersourceTransactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $amount,
                    'currency' => $currency
                ]
            ]
        ];

        try {
            $response = $this->apiClient->processPaymentAction('REFUND', $cybersourceTransactionId, $payload, $orderId, $context);
            if ($response['status'] === 'success') {
                $templateVariables = new ArrayStruct([
                    'source' => 'CyberSourceService'
                ]);
                $context->addExtension('customPaymentUpdate', $templateVariables);
                $this->orderTransactionStateHandler->refund($shopwareOrderTransactionId, $context);
            }
            return new JsonResponse($response);
        } catch (\Exception $exception) {
            throw new APIException($cybersourceTransactionId, 'API_ERROR', $exception->getMessage());
        }
    }

    #[Route(
        path: "/api/cybersource/order/{orderId}/reauthorize",
        name: "api.cybersource.order_reauthorize",
        methods: ["POST"],
        defaults: ["_acl" => ["order.update"]]
    )]
    public function reAuthorize(string $orderId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = (float)($data['amount'] ?? 0.0);

        if ($amount <= 0) {
            throw PaymentException::asyncProcessInterrupted(
                $orderId,
                $this->translator->trans('cybersource_shopware6.exception.CYBERSOURCE_AMOUNT_INCORRECT')
            );
        }

        $orderTransaction = $this->orderService->getOrderTransactionByOrderId($orderId, $context);
        if (empty($orderTransaction)) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND'),
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

        $cybersourceTransactionId = $this->orderService->getCyberSourceTransactionId($orderTransaction->getCustomFields());
        if (!$cybersourceTransactionId) {
            return new JsonResponse(['error' => 'No CyberSource transaction ID found'], 404);
        }

        $shopwareOrderTransactionId = $orderTransaction->getId();
        $currency = $currencyEntity->getIsoCode();
        $uniqueId = $this->orderService->getCyberSourceTransactionUniqueId($orderTransaction->getCustomFields());
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($uniqueId ?? $cybersourceTransactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'additionalAmount' => $amount,
                    'currency' => $currency
                ]
            ],
            'processingInformation' => [
                'authorizationOptions' => [
                    'initiator' => [
                        'storedCredentialUsed' => true
                    ]
                ],
            ],
            'merchantInformation' => [
                'transactionLocalDateTime' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('YmdHis')
            ]
        ];

        try {
            $response = $this->apiClient->processPaymentAction('REAUTHORIZE', $cybersourceTransactionId, $payload, $orderId, $context);
            if ($response['status'] === 'success') {
                $templateVariables = new ArrayStruct([
                    'source' => 'CyberSourceService'
                ]);
                $context->addExtension('customPaymentUpdate', $templateVariables);
            }
            return new JsonResponse($response);
        } catch (\Exception $exception) {
            throw new APIException($cybersourceTransactionId, 'API_ERROR', $exception->getMessage());
        }
    }
}