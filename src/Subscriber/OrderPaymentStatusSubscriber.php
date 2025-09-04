<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Subscriber;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use CyberSource\Shopware6\Service\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class OrderPaymentStatusSubscriber implements EventSubscriberInterface
{
    private OrderService $orderService;
    private StateMachineRegistry $stateMachineRegistry;
    private CyberSourceApiClient $cyberSourceApiClient;
    private LoggerInterface $logger;

    public function __construct(
        OrderService $orderService,
        StateMachineRegistry $stateMachineRegistry,
        CyberSourceApiClient $cyberSourceApiClient,
        LoggerInterface $logger
    ) {
        $this->orderService = $orderService;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->cyberSourceApiClient = $cyberSourceApiClient;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onTransactionStateTransition',
        ];
    }

    public function onTransactionStateTransition(StateMachineTransitionEvent $event): void
    {
        $context = $event->getContext();
        // we change the state of the transaction in the custom payment update
        if ($context->hasExtension('customPaymentUpdate')) {
            return;
        }
        if ($event->getEntityName() !== 'order_transaction') {
            return;
        }

        $transactionId = $event->getEntityId();
        $newState = $event->getToPlace()->getTechnicalName();
        $currentState = $event->getFromPlace()->getTechnicalName();

        $orderTransaction = $this->orderService->getOrderTransaction($transactionId, $context);
        if (!$orderTransaction instanceof OrderTransactionEntity) {
            $this->logger->error("Transaction not found: {$transactionId}");
            return;
        }
        $salesChannelId = $orderTransaction->getOrder()->getSalesChannelId();
        $paymentMethod = $orderTransaction->getPaymentMethod();
        if (!$paymentMethod || $paymentMethod->getHandlerIdentifier() !== 'CyberSource\Shopware6\Gateways\CreditCard') {
            $this->logger->info("Skipping transaction {$transactionId} - not CyberSource.");
            return;
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $cyberSourceTransactionId = $this->orderService->getCyberSourceTransactionId($customFields);
        $cyberSourceUniqId = $this->orderService->getCyberSourceTransactionUniqueId($customFields);
        if (!$cyberSourceTransactionId) {
            $this->logger->error("No CyberSource Transaction ID found for transaction: {$transactionId}");
            return;
        }

        try {
            if (
                (
                    $currentState === OrderTransactionStates::STATE_AUTHORIZED &&
                    $newState === OrderTransactionStates::STATE_PAID
                ) ||
                ($currentState === 'pending_review' && $newState === 'paid')
            ) {
                $this->logger->info(
                    "Capturing transaction {$transactionId} for amount " .
                    $orderTransaction->getAmount()->getTotalPrice()
                );
                $response = $this->capturePayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to capture transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_CAPTURE_FAILED', 'Failed to capture payment.');
                }
            } elseif (
                $currentState === OrderTransactionStates::STATE_AUTHORIZED &&
                ($newState === OrderTransactionStates::STATE_CANCELLED || $newState === 'cancel')
            ) {
                $this->logger->info(
                    "Voiding transaction {$transactionId}."
                );
                $response = $this->voidPayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to void transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_VOID_FAILED', 'Failed to void payment.');
                }
            } elseif (
                $currentState === OrderTransactionStates::STATE_PAID &&
                ($newState === OrderTransactionStates::STATE_REFUNDED || $newState === 'refund')
            ) {
                $this->logger->info(
                    "Refunding transaction {$transactionId} for amount " .
                    $orderTransaction->getAmount()->getTotalPrice()
                );
                $response = $this->refundPayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to refund transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_REFUND_FAILED', 'Failed to refund payment.');
                }
            } elseif (
                $currentState === OrderTransactionStates::STATE_PAID &&
                $newState === OrderTransactionStates::STATE_CANCELLED
            ) {
                $this->logger->info(
                    "Voiding transaction {$transactionId}."
                );
                $response = $this->voidPayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to void transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_VOID_FAILED', 'Failed to void payment.');
                }
            } elseif (
                $currentState === 'pre_review' &&
                $newState === OrderTransactionStates::STATE_AUTHORIZED
            ) {
                $this->logger->info(
                    "Capturing transaction {$transactionId} for amount " .
                    $orderTransaction->getAmount()->getTotalPrice()
                );
                $response = $this->capturePayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to capture transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_CAPTURE_FAILED', 'Failed to capture payment.');
                }
            } elseif (
                $currentState === OrderTransactionStates::STATE_CANCELLED &&
                $newState === OrderTransactionStates::STATE_PAID
            ) {
                $this->logger->info("Reverting transaction {$transactionId} from cancelled to paid.");
                $this->revertTransactionState($transactionId, OrderTransactionStates::STATE_CANCELLED, $context);
            } elseif ($currentState === 'pending_review' && $newState === 'cancel') {
                $this->logger->info("Voiding transaction {$transactionId}.");
                $response = $this->voidPayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to void transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_VOID_FAILED', 'Failed to void payment.');
                }
            } elseif ($currentState === 'pending_review' && $newState === 'paid') {
                $this->logger->info(
                    "Capturing transaction {$transactionId} for amount " .
                    $orderTransaction->getAmount()->getTotalPrice() . "."
                );
                $response = $this->capturePayment(
                    $cyberSourceTransactionId,
                    $cyberSourceUniqId,
                    $orderTransaction,
                    $context,
                    $salesChannelId
                );

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to capture transaction: " . json_encode($response['body']));
                    throw new StateMachineException(400, 'CYBERSOURCE_CAPTURE_FAILED', 'Failed to capture payment.');
                }
            } elseif (
                ($currentState === OrderTransactionStates::STATE_REFUNDED || $currentState === 'refund') &&
                $newState === OrderTransactionStates::STATE_PAID
            ) {
                $this->logger->info("Reverting transaction {$transactionId} from refunded to paid.");
                $this->revertTransactionState($transactionId, OrderTransactionStates::STATE_REFUNDED, $context);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error processing CyberSource request: " . $e->getMessage());
            throw new StateMachineException(
                200,
                "205",
                'Error processing CyberSource request: ' . $e->getMessage(),
                [],
                $e
            );
        }
    }

    private function capturePayment(
        string $transactionId,
        ?string $cyberSourceUniqId,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        ?string $salesChannelId = null
    ): array {
        $order = $orderTransaction->getOrder();
        if (!$order) {
            $this->logger->error("Order not found for transaction: {$transactionId}");
            throw new StateMachineException(400, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        $currency = $order->getCurrency();
        if (!$currency || !$currency->getIsoCode()) {
            throw new StateMachineException(400, 'CURRENCY_NOT_FOUND', 'Currency not found.');
        }
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($cyberSourceUniqId ?? $transactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string)$orderTransaction->getAmount()->getTotalPrice(),
                    'currency' => $currency->getIsoCode(),
                ],
            ],
        ];

        return $this->cyberSourceApiClient->capturePayment(
            $transactionId,
            $payload,
            $orderTransaction->getId(),
            $context,
            $salesChannelId
        );
    }

    private function voidPayment(
        string $transactionId,
        ?string $cyberSourceUniqId,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        ?string $salesChannelId = null
    ): array {
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($cyberSourceUniqId ?? $transactionId),
            ],
        ];

        return $this->cyberSourceApiClient->voidPayment($transactionId, $payload, $orderTransaction->getId(), $context, $salesChannelId);
    }

    private function refundPayment(
        string $transactionId,
        ?string $cyberSourceUniqId,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        ?string $salesChannelId = null
    ): array {
        $order = $orderTransaction->getOrder();
        if (!$order) {
            $this->logger->error("Order not found for transaction: {$transactionId}");
            throw new StateMachineException(400, 'ORDER_NOT_FOUND', 'Order not found.');
        }
        $currency = $order->getCurrency();
        if (!$currency || !$currency->getIsoCode()) {
            throw new StateMachineException(400, 'CURRENCY_NOT_FOUND', 'Currency not found.');
        }
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-' . ($cyberSourceUniqId ?? $transactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string)$orderTransaction->getAmount()->getTotalPrice(),
                    'currency' => $currency->getIsoCode(),
                ],
            ],
        ];
        return $this->cyberSourceApiClient->refundPayment(
            $transactionId,
            $payload,
            $orderTransaction->getId(),
            $context,
            $salesChannelId
        );
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

            $this->logger->info("Available transitions for transaction {$transactionId}: " .
                implode(', ', $availableTransitions));

            if (!in_array($previousState, $availableTransitions, true)) {
                $this->logger->error(
                    "Invalid transition to {$previousState} for transaction {$transactionId}. Valid transitions: " .
                    implode(', ', $availableTransitions)
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
}
