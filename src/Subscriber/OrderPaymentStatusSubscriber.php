<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Subscriber;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Psr\Log\LoggerInterface;

class OrderPaymentStatusSubscriber implements EventSubscriberInterface
{
    private EntityRepository $orderTransactionRepository;
    private StateMachineRegistry $stateMachineRegistry;
    private CyberSourceApiClient $cyberSourceApiClient;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $orderTransactionRepository,
        StateMachineRegistry $stateMachineRegistry,
        CyberSourceApiClient $cyberSourceApiClient,
        LoggerInterface $logger
    ) {
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->cyberSourceApiClient = $cyberSourceApiClient;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ControllerEvent::class => 'onTransactionStateChanged',
        ];
    }

    public function onTransactionStateChanged(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (!in_array($route, [
            'api.action.state_machine.transition',
            'state_machine.order_transaction.state_changed',
            'api.action.order.state_machine.order_transaction.transition_state',
        ], true)) {
            return;
        }

        $context = Context::createDefaultContext();
        $newState = $request->attributes->get('transition');
        $transactionId = $request->attributes->get('orderTransactionId');

        if (!$transactionId || !$newState) {
            $this->logger->error('Missing parameters: Transaction ID or Action Name is NULL.');
            return;
        }

        $criteria = (new Criteria([$transactionId]))
            ->addAssociation('paymentMethod')
            ->addAssociation('stateMachineState')
            ->addAssociation('order.currency');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction instanceof OrderTransactionEntity) {
            $this->logger->error("Transaction not found: {$transactionId}");
            return;
        }

        $paymentMethodHandler = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();
        if (!$paymentMethodHandler) {
            $this->logger->error("Payment method not found for transaction: {$transactionId}");
            return;
        }

        if ($paymentMethodHandler !== 'CyberSource\Shopware6\Gateways\CreditCard') {
            $this->logger->info("Skipping transaction {$transactionId} - not CyberSource.");
            return;
        }

        $currentState = $orderTransaction->getStateMachineState()->getTechnicalName();
        $customFields = $orderTransaction->getCustomFields();
        $cyberSourceTransactionId = $customFields['cybersource_payment_details']['transaction_id'] ?? null;
        $cyberSourceUniqId = $customFields['cybersource_payment_details']['uniqid'] ?? null;

        if (!$cyberSourceTransactionId) {
            $this->logger->error("No CyberSource Transaction ID found for transaction: {$transactionId}");
            return;
        }

        $amount = $orderTransaction->getAmount()->getTotalPrice();

        try {
            if (($currentState === OrderTransactionStates::STATE_AUTHORIZED  && $newState === OrderTransactionStates::STATE_PAID) || ( $currentState === 'pending_review' && $newState === 'paid_authorized')) {
                $this->logger->info("Capturing transaction {$transactionId} for amount {$amount}.");
                $response = $this->capturePayment($cyberSourceTransactionId, $cyberSourceUniqId,$orderTransaction);

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to capture transaction: " . json_encode($response['body']));
                    throw new \RuntimeException('Failed to capture payment.');
                }
            } elseif ($currentState === OrderTransactionStates::STATE_AUTHORIZED && ($newState === OrderTransactionStates::STATE_CANCELLED || $newState === 'cancel')) {
                $this->logger->info("Voiding transaction {$transactionId}.");
                $response = $this->voidPayment($cyberSourceTransactionId, $cyberSourceUniqId,$orderTransaction);

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to void transaction: " . json_encode($response['body']));
                    throw new \RuntimeException('Failed to void payment.');
                }
            } elseif ($currentState === OrderTransactionStates::STATE_PAID && ($newState === OrderTransactionStates::STATE_REFUNDED || $newState === 'refund')) {
                $this->logger->info("Refunding transaction {$transactionId} for amount {$amount}.");
                $response = $this->refundPayment($cyberSourceTransactionId,$cyberSourceUniqId, $orderTransaction);

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to refund transaction: " . json_encode($response['body']));
                    throw new \RuntimeException('Failed to refund payment.');
                }
            }
            elseif ($currentState === OrderTransactionStates::STATE_PAID && $newState === OrderTransactionStates::STATE_CANCELLED) {
                $this->logger->info("Voiding transaction {$transactionId}.");
                $response = $this->voidPayment($cyberSourceTransactionId, $cyberSourceUniqId,$orderTransaction);

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to void transaction: " . json_encode($response['body']));
                    throw new \RuntimeException('Failed to void payment.');
                }
            }
            elseif ($currentState === 'pre_review' && $newState === OrderTransactionStates::STATE_AUTHORIZED) {
                $this->logger->info("Capturing transaction {$transactionId} for amount {$amount}.");
                //todo check can we capture the payment here? or should we need to make authorize first?
                $response = $this->capturePayment($cyberSourceTransactionId, $cyberSourceUniqId,$orderTransaction);

                if ($response['statusCode'] !== 201) {
                    $this->revertTransactionState($transactionId, $currentState, $context);
                    $this->logger->error("Failed to capture transaction: " . json_encode($response['body']));
                    throw new \RuntimeException('Failed to capture payment.');
                }
            }
            else {
                $this->logger->info("No action required for transaction {$transactionId}.");
            }
        } catch (\Exception $e) {
            $this->logger->error("Error processing CyberSource request: " . $e->getMessage());
            throw $e;
        }
    }

    private function capturePayment(string $transactionId, string $cyberSourceUniqId, OrderTransactionEntity $orderTransaction): array
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-'. $cyberSourceUniqId,
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $orderTransaction->getAmount()->getTotalPrice(),
                    'currency' => $orderTransaction->getOrder()->getCurrency()->getIsoCode(),
                ],
            ],
        ];

        return $this->cyberSourceApiClient->capturePayment($transactionId, $payload);
    }

    private function voidPayment(string $transactionId, string $cyberSourceUniqId, OrderTransactionEntity $orderTransaction): array
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-'. $cyberSourceUniqId,
            ],
        ];

        return $this->cyberSourceApiClient->voidPayment($transactionId, $payload);
    }

    private function refundPayment(string $transactionId,string $cyberSourceUniqId, OrderTransactionEntity $orderTransaction): array
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => 'Order-'. $cyberSourceUniqId,
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) $orderTransaction->getAmount()->getTotalPrice(),
                    'currency' => $orderTransaction->getOrder()->getCurrency()->getIsoCode(),
                ],
            ],
        ];

        return $this->cyberSourceApiClient->refundPayment($transactionId, $payload);
    }

    private function revertTransactionState(string $transactionId, string $previousState, Context $context): void
    {
        try {
            $this->logger->warning("Reverting transaction {$transactionId} to previous state: {$previousState}");
            $this->stateMachineRegistry->transition(
                new Transition(
                    'order_transaction',
                    $transactionId,
                    $previousState,
                    'stateId'
                ),
                $context
            );
        } catch (\Exception $e) {
            $this->logger->error("Failed to revert transaction state: " . $e->getMessage());
        }
    }
}