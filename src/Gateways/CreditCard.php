<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Gateways;

use Shopware\Core\Framework\Context;
use CyberSource\Shopware6\Library\CyberSource;
use CyberSource\Shopware6\Exceptions\APIException;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

final class CreditCard implements SynchronousPaymentHandlerInterface
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly CyberSourceFactory $cyberSourceFactory,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly EntityRepository $orderTransactionRepo,
        private readonly StateMachineRegistry $stateMachineRegistry
    ) {}

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
            throw new APIException($orderTransactionId, 'MISSING_TRANSACTION_ID', 'Missing CyberSource transaction ID.');
        }

        $status = $dataBag->get('cybersource_payment_status');
        if (!$status) {
            throw new APIException($orderTransactionId, 'MISSING_PAYMENT_STATUS', 'Missing CyberSource payment status.');
        }

        // Save transaction ID to customFields
        $this->updateOrderTransactionCustomFields(['id' => $transactionId, 'uniqid' => $uniqid], $orderTransactionId, $context);

        switch ($status) {
            case 'AUTHORIZED':
                $this->orderTransactionStateHandler->authorize($orderTransactionId, $context);
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
                $this->setPaymentStatus($orderTransactionId, 'open', $context);
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

    private function updateOrderTransactionCustomFields(array $response, string $orderTransactionId, Context $context): void
    {
        $this->orderTransactionRepo->update([
            [
                'id' => $orderTransactionId,
                'customFields' => [
                    'cybersource_payment_details' => [
                        'transaction_id' => $response['id'] ?? null,
                        'uniqid' => $response['uniqid'] ?? null,
                    ]
                ]
            ]
        ], $context);
    }
}