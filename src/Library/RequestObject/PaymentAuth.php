<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestObject;

use CyberSource\Shopware6\Objects\Card;
use CyberSource\Shopware6\Objects\Order;
use CyberSource\Shopware6\Mappers\CardMapper;
use Shopware\Core\Checkout\Order\OrderEntity;
use CyberSource\Shopware6\Mappers\OrderMapper;
use CyberSource\Shopware6\Objects\ClientReference;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use CyberSource\Shopware6\Objects\PaymentInstrument;
use CyberSource\Shopware6\Mappers\PaymentInstrumentMapper;
use CyberSource\Shopware6\Mappers\OrderClientReferenceMapper;

class PaymentAuth
{
    private readonly ClientReference $clientReference;
    private Card $card;
    private readonly Order $order;
    private PaymentInstrument $paymentInstrument;
    private readonly bool $isSavedCardChecked;

    /**
     * __construct
     *
     * @param  OrderEntity $order
     * @param  CustomerEntity $customer
     * @param  array $cardInformation
     *
     * @return void
     */
    public function __construct(
        OrderEntity $order,
        CustomerEntity $customer,
        array $cardInformation,
        bool $isSavedCardChecked
    ) {
        $this->isSavedCardChecked = $isSavedCardChecked;
        $this->clientReference = OrderClientReferenceMapper::mapToClientReference(
            $order
        );
        $this->order = OrderMapper::mapToOrder($order, $customer);
        if ($isSavedCardChecked === true) {
            $this->paymentInstrument = PaymentInstrumentMapper::mapToPaymentInstrument($cardInformation);
        } else {
            $this->card = CardMapper::mapToCard($cardInformation);
        }
    }

    /**
     * makePaymentRequest
     *
     * @return array
     */
    public function makePaymentRequest(): array
    {
        $paymentData = array_merge(
            $this->clientReference->toArray(),
            $this->order->toArray()
        );

        if ($this->isSavedCardChecked === true) {
            $paymentData = array_merge($paymentData, $this->paymentInstrument->toArray());
        } else {
            $paymentData = array_merge($paymentData, $this->card->toArray());
        }

        return $paymentData;
    }

    /**
     * makeCapturePaymentRequest
     *
     * @return array
     */
    public function makeCapturePaymentRequest(): array
    {
        return array_merge(
            $this->clientReference->toArray(),
            $this->order->toCaptureArray()
        );
    }

    /**
     * makeAuthReversalPaymentRequest
     *
     * @return array
     */
    public function makeAuthReversalPaymentRequest(): array
    {
        return array_merge(
            $this->clientReference->toArray(),
            $this->order->toAuthReversalArray()
        );
    }

    public function getCybersourceCustomerRequestPayload(string $customerId, string $email): array
    {
        return array_merge(
            [
                "buyerInformation" =>
                [
                    "merchantCustomerID" => $customerId,
                    "email" => $email,
                ],
            ],
            $this->clientReference->toArray()
        );
    }
}
