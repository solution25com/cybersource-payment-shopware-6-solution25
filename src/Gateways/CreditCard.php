<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Gateways;

use CyberSource\Shopware6\Service\ConfigurationService;
use CyberSource\Shopware6\Service\PaymentProofSigner;
use CyberSource\Shopware6\Service\TransactionLogger;
use CyberSource\Shopware6\Exceptions\APIException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class CreditCard extends AbstractPaymentHandler
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly TransactionLogger $transactionLogger,
        /** @var EntityRepository<OrderTransactionCollection> $orderTransactionRepository */
        private readonly EntityRepository $orderTransactionRepository,
        private readonly PaymentProofSigner $paymentProofSigner
    ) {
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $paymentProofToken = $request->get('cybersource_payment_proof');
        if (!is_string($paymentProofToken) || $paymentProofToken === '') {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'MISSING_PAYMENT_PROOF',
                'Missing signed CyberSource payment proof.'
            );
        }

        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.orderCustomer');
        $orderTransactionEntity = $this->orderTransactionRepository->search($criteria, $context)->first();
        if (!$orderTransactionEntity instanceof OrderTransactionEntity) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'ORDER_TRANSACTION_NOT_FOUND',
                'Order transaction not found.'
            );
        }

        $paymentProof = $this->paymentProofSigner->verify($paymentProofToken);
        if ($paymentProof === null || ($paymentProof['stage'] ?? null) !== 'completed') {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'INVALID_PAYMENT_PROOF',
                'Invalid CyberSource payment proof.'
            );
        }

        $transactionId = $request->get('cybersource_transaction_id');
        if (!is_string($transactionId) || $transactionId === '') {
            $transactionId = $paymentProof['transactionId'] ?? null;
        }
        if (!is_string($transactionId) || $transactionId === '') {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'MISSING_TRANSACTION_ID',
                'Missing CyberSource transaction ID.'
            );
        }

        $status = $paymentProof['status'] ?? null;
        if (!is_string($status) || $status === '') {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'MISSING_PAYMENT_STATUS',
                'Missing CyberSource payment status.'
            );
        }

        if (($paymentProof['transactionId'] ?? null) !== $transactionId) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'INVALID_TRANSACTION_ID',
                'CyberSource transaction ID does not match the signed proof.'
            );
        }

        $orderEntity = $orderTransactionEntity->getOrder();
        if ($orderEntity === null) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'ORDER_NOT_FOUND',
                'Order not found for transaction.'
            );
        }

        $salesChannelId = $orderEntity->getSalesChannelId();
        $orderAmount = $this->normalizeAmount($orderEntity->getAmountTotal());
        $paymentAmount = $this->normalizeAmount($paymentProof['amount'] ?? null);
        $orderCurrency = $orderEntity->getCurrency()?->getIsoCode();
        $paymentCurrency = $paymentProof['currency'] ?? null;
        if (
            $salesChannelId === '' ||
            ($paymentProof['salesChannelId'] ?? null) !== $salesChannelId ||
            $paymentAmount === null ||
            $paymentAmount !== $orderAmount ||
            !is_string($paymentCurrency) ||
            $paymentCurrency !== $orderCurrency
        ) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'INVALID_PAYMENT_CONTEXT',
                'CyberSource payment proof does not match the order.'
            );
        }

        $orderCustomer = $orderEntity->getOrderCustomer();
        $orderCustomerId = $orderCustomer?->getCustomerId();
        $paymentCustomerId = $paymentProof['customerId'] ?? null;
        if (
            is_string($paymentCustomerId) &&
            $paymentCustomerId !== '' &&
            is_string($orderCustomerId) &&
            $orderCustomerId !== '' &&
            $paymentCustomerId !== $orderCustomerId
        ) {
            $this->setPaymentStatus($orderTransactionId, 'fail', $context);
            throw new APIException(
                $orderTransactionId,
                'INVALID_CUSTOMER_CONTEXT',
                'CyberSource payment proof does not match the order customer.'
            );
        }

        $transactionType = $this->configurationService->getTransactionType($salesChannelId);
        $createdAtDate = $orderTransactionEntity->getCreatedAt();
        $createdAt = $createdAtDate?->format('Y-m-d\TH:i:s\Z') ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $paymentData = $paymentProof['paymentData'] ?? null;
        if (is_array($paymentData)) {
            $paymentDataJson = json_encode($paymentData);
            if (is_string($paymentDataJson)) {
                $this->transactionLogger->logTransactionFromDataBag(
                    $transactionType === 'auth' ? 'Authorized' : 'Payment',
                    $paymentDataJson,
                    $orderTransactionId,
                    $context,
                    $createdAt,
                    $paymentProof['uniqid'] ?? null
                );
            }
        }
        $templateVariables = new ArrayStruct([
            'source' => 'CyberSourceService'
        ]);
        $context->addExtension('customPaymentUpdate', $templateVariables);
        switch ($status) {
            case 'AUTHORIZED':
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
        return null;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
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

    private function normalizeAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
