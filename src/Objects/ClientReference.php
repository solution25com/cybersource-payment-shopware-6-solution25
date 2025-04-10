<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Objects;

class ClientReference
{
    private readonly string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function toArray(): array
    {
        return [
            'clientReferenceInformation' => [
                'code' => $this->code,
            ],
        ];
    }
}
