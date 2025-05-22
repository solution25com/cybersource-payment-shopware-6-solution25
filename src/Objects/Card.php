<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Objects;

class Card
{
    private readonly string $transientToken;
    private readonly int $expirationMonth;
    private readonly int $expirationYear;

    public function __construct(
        string $transientToken,
        int $expirationMonth,
        int $expirationYear
    ) {
        $this->transientToken = $transientToken;
        $this->expirationMonth = $expirationMonth;
        $this->expirationYear = $expirationYear;
    }

    public function toArray(): array
    {
        return [
            'tokenInformation' => [
                'transientTokenJwt' => $this->transientToken
            ],
            'paymentInformation' => [
                'card' => [
                    'expirationMonth' => str_pad((string) $this->expirationMonth, 2, '0', STR_PAD_LEFT),
                    'expirationYear' => (string) $this->expirationYear
                ]
            ]
        ];
    }
}
