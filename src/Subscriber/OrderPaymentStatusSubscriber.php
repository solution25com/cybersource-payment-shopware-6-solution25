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
        $orderId = $orderTransaction->getOrderId();
        $paymentMethod = $orderTransaction->getPaymentMethod();
        if (!$paymentMethod || $paymentMethod->getHandlerIdentifier() !== 'CyberSource\Shopware6\Gateways\CreditCard') {
            $this->logger->info("Skipping transaction {$transactionId} - not CyberSource.");
            return;
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $cyberSourceTransactionId = $this->orderService->getCyberSourceTransactionId($customFields);
        if (!$cyberSourceTransactionId) {
            $this->logger->error("No CyberSource Transaction ID found for transaction: {$transactionId}");
            return;
        }

        try {
            $response = $this->cyberSourceApiClient->transitionOrderPayment(
                $orderId,
                $newState,
                $currentState,
                $context,
                true
            );
            if (!$response['success']) {
                $this->revertTransactionState($transactionId, $currentState, $context);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error transitioning CyberSource payment state: " . $e->getMessage());
            throw new StateMachineException(
                200,
                "205",
                'Error transitioning CyberSource payment state: ' . $e->getMessage(),
                [],
                $e
            );
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
