<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Validation;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Validation\CardValidator;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Exceptions\BadRequestException;

class CardValidatorTest extends TestCase
{
    private $translator;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
    }

    public function testValidCard(): void
    {
        $cardValidator = new CardValidator($this->translator);

        $this->assertNull($cardValidator->validate('orderTransactionId', [
            'cardNumber' => '4111111111111111',
            'expirationDate' => '12/25',
            'securityCode' => '123',
        ]));
    }

    public function testInvalidCard(): void
    {
        $cardValidator = new CardValidator($this->translator);

        $this->expectException(BadRequestException::class);

        $cardValidator->validate('orderTransactionId', [
            'cardNumber' => '123',
            'expirationDate' => '12/23',
            'securityCode' => '123',
        ]);
    }
}
