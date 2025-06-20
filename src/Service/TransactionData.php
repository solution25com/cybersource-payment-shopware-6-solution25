<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

class TransactionData
{
    private string $type;
    private ?string $transactionId;
    private ?string $paymentId;
    private ?string $cardCategory;
    private ?string $paymentMethodType;
    private ?string $expiryMonth;
    private ?string $expiryYear;
    private ?string $cardLast4;
    private ?string $gatewayAuthorizationCode;
    private ?string $gatewayToken;
    private ?string $gatewayReference;
    private string $lastUpdate;
    private ?string $uniqid;

    public function __construct(
        string $type,
        ?string $transactionId,
        ?string $paymentId,
        ?string $cardCategory,
        ?string $paymentMethodType,
        ?string $expiryMonth,
        ?string $expiryYear,
        ?string $cardLast4,
        ?string $gatewayAuthorizationCode,
        ?string $gatewayToken,
        ?string $gatewayReference,
        string $lastUpdate,
        ?string $uniqid = null
    ) {
        $this->type = $type;
        $this->transactionId = $transactionId;
        $this->paymentId = $paymentId;
        $this->cardCategory = $cardCategory;
        $this->paymentMethodType = $paymentMethodType;
        $this->expiryMonth = $expiryMonth;
        $this->expiryYear = $expiryYear;
        $this->cardLast4 = $cardLast4;
        $this->gatewayAuthorizationCode = $gatewayAuthorizationCode;
        $this->gatewayToken = $gatewayToken;
        $this->gatewayReference = $gatewayReference;
        $this->lastUpdate = $lastUpdate;
        $this->uniqid = $uniqid;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'transaction_id' => $this->transactionId,
            'payment_id' => $this->paymentId,
            'card_category' => $this->cardCategory,
            'payment_method_type' => $this->paymentMethodType,
            'expiry_month' => $this->expiryMonth,
            'expiry_year' => $this->expiryYear,
            'card_last_4' => $this->cardLast4,
            'gateway_authorization_code' => $this->gatewayAuthorizationCode,
            'gateway_token' => $this->gatewayToken,
            'gateway_reference' => $this->gatewayReference,
            'last_update' => $this->lastUpdate,
            'uniqid' => $this->uniqid,
        ];
    }
}