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
            paymentId: $this->generatePaymentId(),
            cardCategory: $this->getCardCategory($responseData['paymentInformation']['card']['brand'] ?? null),
            paymentMethodType: $responseData['paymentInformation']['card']['brand'] ?? null,
            expiryMonth: $responseData['paymentInformation']['card']['expirationMonth'] ?? null,
            expiryYear: $responseData['paymentInformation']['card']['expirationYear'] ?? null,
            cardLast4: isset($responseData['paymentInformation']['card']['number']) ? substr($responseData['paymentInformation']['card']['number'], -4) : null,
            gatewayAuthorizationCode: $responseData['processorInformation']['approvalCode'] ?? null,
            gatewayToken: $responseData['tokenInformation']['paymentInstrument']['id'] ?? null,
            gatewayReference: $responseData['processorInformation']['transactionId'] ?? null,
            lastUpdate: date('c'),
            uniqid: $uniqid,
            amount: (string)$responseData['orderInformation']['amountDetails']['totalAmount'] ?? null,
            currency: $responseData['orderInformation']['amountDetails']['currency'] ?? null,
            statusCode: $responseData['statusCode'] ?? null
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
                paymentId: $this->generatePaymentId(),
                cardCategory: $this->getCardCategory($paymentData['payment_method_type']),
                paymentMethodType: $this->getPaymentMethodTypeText( $paymentData['payment_method_type']),
                expiryMonth: $paymentData['expiry_month'],
                expiryYear: $paymentData['expiry_year'],
                cardLast4: $paymentData['card_last_4'],
                gatewayAuthorizationCode: $paymentData['gateway_authorization_code'],
                gatewayToken: $paymentData['gateway_token'],
                gatewayReference: $paymentData['gateway_reference'],
                lastUpdate: date('c'),
                uniqid: $uniqid,
                amount: $paymentData['amount'] ?? null,
                currency: $paymentData['currency'],
                statusCode: $paymentData['statusCode'] ?? null
            );

            $this->orderService->updateOrderTransactionCustomFields($transactionData->toArray(), $orderTransactionId, $context);
        }
    }
    private function generatePaymentId(): string
    {
        return bin2hex(random_bytes(15)); // Generates a random string of 30 characters
    }
    public function getCardCategory(?string $scheme): string
    {
        if (!$scheme) {
            return '-';
        }
        return str_contains($scheme, 'debit') ? 'DebitCard' : 'CreditCard';
    }

    private function getPaymentMethodTypeText(mixed $payment_method_type)
    {
        return match ($payment_method_type) {
            '001' => 'Visa',
            '002' => 'Mastercard',
            '003' => 'American Express',
            '004' => 'Discover',
            '005' => 'Diners Club',
            '006' => 'Carte Blanche',
            '007' => 'JCB',
            '014' => 'EnRoute',
            '021' => 'JAL',
            '024' => 'Maestro (UK Domestic)',
            '033' => 'Visa Electron',
            '034' => 'Dankort',
            '036' => 'Cartes Bancaires',
            '037' => 'Carta Si',
            '039' => 'Encoded account number',
            '040' => 'UATP',
            '042' => 'Maestro (International)',
            '050' => 'Hipercard',
            '051' => 'Aura',
            '054' => 'Elo',
            '062' => 'China UnionPay',
            '058' => 'Carnet',
            default => $payment_method_type,
        };
    }

}