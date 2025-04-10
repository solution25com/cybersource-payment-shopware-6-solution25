<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Objects;

class Order
{
    private readonly float $totalAmount;
    private readonly string $currency;
    private readonly BillTo $billTo;
    private array $lineItems;

    /**
     * __construct
     *
     * @param  float $totalAmount
     * @param  string $currency
     * @param  BillTo $billTo
     * @param  Array $lineItems
     * @return void
     */
    public function __construct(float $totalAmount, string $currency, BillTo $billTo, array $lineItems)
    {
        $this->totalAmount = $totalAmount;
        $this->currency = $currency;
        $this->billTo = $billTo;
        $this->lineItems = $lineItems;
    }

    /**
     * toArray
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'orderInformation' => [
                'billTo' => $this->billTo->toArray(),
                'lineItems' => $this->lineItems,
                'amountDetails' => [
                    'totalAmount' => $this->totalAmount,
                    'currency' => $this->currency,
                ],
            ]
        ];
    }

    /**
     * toCaptureArray
     *
     * @return array
     */
    public function toCaptureArray(): array
    {
        return [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $this->totalAmount,
                    'currency' => $this->currency,
                ],
                'lineItems' => $this->lineItems
            ],
        ];
    }

    /**
     * toAuthReversalArray
     *
     * @return array
     */
    public function toAuthReversalArray(): array
    {
        //INFO: Default reason for logging purposes only, not displaying to the user
        return [
            'reversalInformation' => [
                'amountDetails' => [
                    'totalAmount' => $this->totalAmount
                ],
            ],
            "reason" => 'An error occurred while processing the payment request.
                The attempted authorization has been reversed.'
        ];
    }

    public function toPaymentInstrumentBillToArray(): array
    {
        return [
            'billTo' => $this->billTo->toArray(),
        ];
    }
}
