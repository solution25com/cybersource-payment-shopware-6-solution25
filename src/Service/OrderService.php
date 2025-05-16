<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;
use CyberSource\Shopware6\Mappers\OrderClientReferenceMapper;
use CyberSource\Shopware6\Mappers\OrderMapper;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

class OrderService
{
    private EntityRepository $orderTransactionRepository;
    private TranslatorInterface $translator;
    public function __construct(
        EntityRepository $orderTransactionRepository,
        TranslatorInterface $translator,
    )
    {
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->translator = $translator;
    }
    public function getOrderTransaction($transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = (new Criteria([$transactionId]))
            ->addAssociation('paymentMethod')
            ->addAssociation('stateMachineState')
            ->addAssociation('order.currency');

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

    public function update(array $array, Context $context)
    {
        $this->orderTransactionRepository->update($array, $context);
    }

    public function getOrderTransactionByOrderId(string $orderId, Context $context)
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('stateMachineState');
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }


    public function getPaymentStatus($orderTransaction): ?string
    {
        $stateMachineStateEntity = $orderTransaction->getStateMachineState();
        return $stateMachineStateEntity->getTechnicalName();
    }

    public function transformLineItems($orderLineItems): array
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

    public function getCybersourcePaymentTransactionId($orderTransaction, $paymentStatus)
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
    }


    public function getTransactionFromCustomFieldsDetails(mixed $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter(
            'customFields.cybersource_payment_details.transaction_id',
            $transactionId
        ));
        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

}