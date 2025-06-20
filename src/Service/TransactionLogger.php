<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Psr\Log\LoggerInterface;

class TransactionLogger
{
    private OrderService $orderService;

    public function __construct(
        OrderService $orderService
    ) {
        $this->orderService = $orderService;
    }

    public function logTransaction(
        string $type,
        array $responseData,
        string $orderTransactionId,
        Context $context,
        ?string $uniqid = null
    ): void {
        $transactionData = new TransactionData(
            type: $type,
            transactionId: $responseData['id'] ?? null,
            paymentId: $responseData['clientReferenceInformation']['code'] ?? null,
            cardCategory: $responseData['paymentInformation']['card']['type'] ?? null,
            paymentMethodType: $responseData['paymentInformation']['card']['brand'] ?? null,
            expiryMonth: $responseData['paymentInformation']['card']['expirationMonth'] ?? null,
            expiryYear: $responseData['paymentInformation']['card']['expirationYear'] ?? null,
            cardLast4: isset($responseData['paymentInformation']['card']['number']) ? substr($responseData['paymentInformation']['card']['number'], -4) : null,
            gatewayAuthorizationCode: $responseData['processorInformation']['authorizationCode'] ?? null,
            gatewayToken: $responseData['tokenInformation']['paymentInstrument']['id'] ?? null,
            gatewayReference: $responseData['processorInformation']['transactionId'] ?? null,
            lastUpdate: date('c'),
            uniqid: $uniqid
        );

        $this->orderService->updateOrderTransactionCustomFields($transactionData->toArray(), $orderTransactionId, $context);
    }

    public function logTransactionFromDataBag(
        string  $type,
        string  $paymentDataJson,
        string  $orderTransactionId,
        Context $context,
        ?string $uniqid = null
    ): void {
        $paymentData = json_decode($paymentDataJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {

            $transactionData = new TransactionData(
                type: $type,
                transactionId: $paymentData['cybersource_transaction_id'],
                paymentId: $paymentData['payment_id'],
                cardCategory: $paymentData['card_category'],
                paymentMethodType: $paymentData['payment_method_type'],
                expiryMonth: $paymentData['expiry_month'],
                expiryYear: $paymentData['expiry_year'],
                cardLast4: $paymentData['card_last_4'],
                gatewayAuthorizationCode: $paymentData['gateway_authorization_code'],
                gatewayToken: $paymentData['gateway_token'],
                gatewayReference: $paymentData['gateway_reference'],
                lastUpdate: date('c'),
                uniqid: $uniqid
            );

            $this->orderService->updateOrderTransactionCustomFields($transactionData->toArray(), $orderTransactionId, $context);
        }
    }

}