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

final class CreditCard implements SynchronousPaymentHandlerInterface
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly CyberSourceFactory $cyberSourceFactory,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly EntityRepository $orderTransactionRepo
    ) {}

    public function pay(
        SyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        $transactionId = $dataBag->get('cybersource_transaction_id');
        if (!$transactionId) {
            throw new APIException($orderTransactionId, 'MISSING_TRANSACTION_ID', 'Missing CyberSource transaction ID.');
        }

        // Save transaction ID to customFields
        $this->updateOrderTransactionCustomFields(['id' => $transactionId], $orderTransactionId, $context);

        // Check transaction type from config
        $transactionType = $this->configurationService->getTransactionType();

        if ($transactionType === 'auth') {
            $this->orderTransactionStateHandler->authorize($orderTransactionId, $context);
        } elseif ($transactionType === 'auth_capture') {
            $this->orderTransactionStateHandler->paid($orderTransactionId, $context);
        }
    }

    private function updateOrderTransactionCustomFields(array $response, string $orderTransactionId, Context $context): void
    {
        $this->orderTransactionRepo->update([
            [
                'id' => $orderTransactionId,
                'customFields' => [
                    'cybersource_payment_details' => [
                        'transaction_id' => $response['id'] ?? null
                    ]
                ]
            ]
        ], $context);
    }
}
