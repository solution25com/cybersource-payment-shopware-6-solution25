<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Gateways;

use CyberSource\Shopware6\Service\ConfigurationService;
use CyberSource\Shopware6\Service\OrderService;
use CyberSource\Shopware6\Service\TransactionLogger;
use CyberSource\Shopware6\Exceptions\APIException;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

final class CreditCard implements SynchronousPaymentHandlerInterface
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly TransactionLogger $transactionLogger,
    ) {
    }

    public function pay(
        SyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        $transactionId = $dataBag->get('cybersource_transaction_id');
        $uniqid = $dataBag->get('cybersource_payment_uniqid');
        if (!$transactionId) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'MISSING_TRANSACTION_ID',
                'Missing CyberSource transaction ID.'
            );
        }

        $status = $dataBag->get('cybersource_payment_status');
        if (!$status) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'MISSING_PAYMENT_STATUS',
                'Missing CyberSource payment status.'
            );
        }
        $cybersource_payment_data = $dataBag->get('cybersource_payment_data');
        $this->transactionLogger->logTransactionFromDataBag(
            'Authorized',
            $cybersource_payment_data,
            $orderTransactionId,
            $context,
            $uniqid
        );

        switch ($status) {
            case 'AUTHORIZED':
                $transactionType = $this->configurationService->getTransactionType();
                if ($transactionType === 'auth') {
                    $this->orderTransactionStateHandler->authorize($orderTransactionId, $context);
                } else {
                    $this->orderTransactionStateHandler->paid($orderTransactionId, $context);
                }
                break;

            case 'PARTIAL_AUTHORIZED':
                $this->orderTransactionStateHandler->authorize($orderTransactionId, $context);
                break;

            case 'AUTHORIZED_PENDING_REVIEW':
                $this->setPaymentStatus($orderTransactionId, 'pending_review', $context);
                break;

            case 'PENDING_REVIEW':
                $this->setPaymentStatus($orderTransactionId, 'pre_review', $context);
                break;

            case 'DECLINED':
                $this->orderTransactionStateHandler->fail($orderTransactionId, $context);
                break;

            default:
                $this->setPaymentStatus($orderTransactionId, 'fail', $context);
                break;
        }
    }

    private function setPaymentStatus(string $orderTransactionId, string $newState, Context $context): bool
    {
        try {
            $transition = new Transition(
                'order_transaction',
                $orderTransactionId,
                $newState,
                'stateId'
            );

            $this->stateMachineRegistry->transition($transition, $context);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
