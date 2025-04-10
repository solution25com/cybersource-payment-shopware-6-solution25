<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Objects;

class PaymentInstrument
{
    private readonly string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function toArray(): array
    {
        return [
            'paymentInformation' => [
                'paymentInstrument' => [
                    'id' => $this->id,
                ],
            ],
        ];
    }
}
