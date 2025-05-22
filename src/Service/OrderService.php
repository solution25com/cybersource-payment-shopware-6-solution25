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
        return $stateMachineStateEntity instanceof StateMachineStateEntity ? $stateMachineStateEntity->getTechnicalName() : null;
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

    public function getCybersourcePaymentTransactionId(OrderTransactionEntity $orderTransaction, string $paymentStatus): ?string
    {
        $customField = $orderTransaction->getCustomFields();
        if (!empty($customField['cybersource_payment_details']['transaction_id'])) {
            return $customField['cybersource_payment_details']['transaction_id'];
        }

        if ($paymentStatus !== 'failed') {
            throw new OrderTransactionNotFoundException(
                $this->translator->trans(
                    'cybersource_shopware6.exception.CYBERSOURCE_ORDER_TRANSACTION_NOT_FOUND'
                ),
                'ORDER_TRANSACTION_NOT_FOUND'
            );
        }
        return null;
    }


    public function getTransactionFromCustomFieldsDetails(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter(
            'customFields.cybersource_payment_details.transaction_id',
            $transactionId
        ));
        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }
}
