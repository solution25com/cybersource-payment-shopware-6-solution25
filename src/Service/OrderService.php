<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;
use CyberSource\Shopware6\Mappers\OrderClientReferenceMapper;
use CyberSource\Shopware6\Mappers\OrderMapper;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;

class OrderService
{
    /**
     * @var EntityRepository<OrderTransactionCollection>
     */
    private EntityRepository $orderTransactionRepository;
    private TranslatorInterface $translator;
    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     */
    public function __construct(
        EntityRepository $orderTransactionRepository,
        TranslatorInterface $translator,
    ) {
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->translator = $translator;
    }
    public function getOrderTransaction(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = (new Criteria([$transactionId]))
            ->addAssociation('paymentMethod')
            ->addAssociation('stateMachineState')
            ->addAssociation('order.currency');

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

    public function update(array $array, Context $context): void
    {
        $this->orderTransactionRepository->update($array, $context);
    }

    public function getOrderTransactionByOrderId(string $orderId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('stateMachineState');
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }


    public function getPaymentStatus(OrderTransactionEntity $orderTransaction): ?string
    {
        $stateMachineStateEntity = $orderTransaction->getStateMachineState();
        return $stateMachineStateEntity instanceof StateMachineStateEntity ?
            $stateMachineStateEntity->getTechnicalName() : null;
    }

    public function transformLineItems(OrderLineItemCollection $orderLineItems): array
    {
        $orderLineItemData = OrderMapper::formatLineItemData($orderLineItems);
        return $orderLineItemData;
    }

    public function getClientReference(OrderEntity $orderEntity): array
    {
        return OrderClientReferenceMapper::mapToClientReference(
            $orderEntity
        )->toArray();
    }

    public function getTransactionFromCustomFieldsDetails(
        string $transactionId,
        Context $context
    ): ?OrderTransactionEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter(
            'customFields.cybersource_payment_details.transaction_id',
            $transactionId
        ));
        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }
    public function updateOrderTransactionCustomFields(
        array $newTransaction,
        string $orderTransactionId,
        Context $context
    ): void {
        $orderTransaction = $this->getOrderTransaction($orderTransactionId, $context);
        if (!$orderTransaction instanceof OrderTransactionEntity) {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans('cybersource_shopware6.exception.CYBERSOURCE_ORDER_TRANSACTION_NOT_FOUND'),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
        $customFields = $orderTransaction->getCustomFields() ?? [];
        $transactions = $customFields['cybersource_payment_details']['transactions'] ?? [];
        $transactions[] = $newTransaction;
        $customFields['cybersource_payment_details'] = [
            'transactions' => $transactions,
        ];
        $this->update([
            [
                'id' => $orderTransactionId,
                'customFields' => $customFields,
            ],
        ], $context);
    }
    public function getCyberSourceTransactionId(?array $customFields): ?string
    {
        if (!$customFields || !isset($customFields['cybersource_payment_details'])) {
            return null;
        }
        $details = $customFields['cybersource_payment_details'];
        // check for new structure with transactions
        if (isset($details['transactions']) && is_array($details['transactions']) && !empty($details['transactions'])) {
            // get the first transaction's ID
            return $details['transactions'][0]['transaction_id'] ?? null;
        }
        // fallback to old structure
        return $details['transaction_id'] ?? null;
    }
    public function getCyberSourceTransactionUniqueId(?array $customFields): ?string
    {
        if (!$customFields || !isset($customFields['cybersource_payment_details'])) {
            return null;
        }
        $details = $customFields['cybersource_payment_details'];
        // check for new structure with transactions
        if (isset($details['transactions']) && is_array($details['transactions']) && !empty($details['transactions'])) {
            // get the first transaction's unikid
            return $details['transactions'][0]['uniqid'] ?? null;
        }
        // fallback to old structure
        return $details['uniqid'] ?? null;
    }
    public function getCyberSourceTransactionPaymentId(?array $customFields): ?string
    {
        if (!$customFields || !isset($customFields['cybersource_payment_details'])) {
            return null;
        }
        $details = $customFields['cybersource_payment_details'];
        if (isset($details['transactions']) && is_array($details['transactions']) && !empty($details['transactions'])) {
            return $details['transactions'][0]['payment_id'] ?? null;
        }
        return null;
    }
}
