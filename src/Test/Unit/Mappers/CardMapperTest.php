<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Mappers;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\Card;
use CyberSource\Shopware6\Mappers\CardMapper;

class CardMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function testMapToCard()
    {
        $faker = \Faker\Factory::create();
        $expirationDate = $faker->creditCardExpirationDateString();
        $cardNumber = $faker->creditCardNumber();
        $securityCode = $faker->randomNumber(3, true);
        $date = explode('/', $expirationDate);
        $expirationMonth = (int) $date[0];
        $expirationYear = (int) $date[1];

        $cardInformation = [
            'expirationDate' => $expirationDate,
            'cardNumber' => $cardNumber,
            'securityCode' => $securityCode
        ];

        $expectedCard = new Card(
            $cardInformation['cardNumber'],
            $expirationMonth,
            $expirationYear,
            $securityCode
        );

        $eventSubscriberClass = CardMapper::mapToCard($cardInformation);
        $this->assertEquals($expectedCard, $eventSubscriberClass);
    }
}
