<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Objects;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\Card;

class CardTest extends TestCase
{
    public function testToArrayFromCardClass()
    {
        $faker = \Faker\Factory::create();

        $number = $faker->creditCardNumber;
        $expirationMonth = $faker->numberBetween(1, 12);
        $expirationYear = $faker->numberBetween(date('Y'), date('Y') + 10);
        $securityCode = $faker->numberBetween(100, 999);

        $card = new Card(
            $number,
            $expirationMonth,
            $expirationYear,
            $securityCode
        );
        $expectedArray = [
            'paymentInformation' => [
                'card' => [
                    'expirationYear' => $expirationYear,
                    'number' => $number,
                    'securityCode' => $securityCode,
                    'expirationMonth' => $expirationMonth,
                ],
            ]
        ];
        $this->assertEquals($expectedArray, $card->toArray());
    }

    public function testToInstrumentArray()
    {
        $faker = \Faker\Factory::create();

        $number = $faker->creditCardNumber;
        $expirationMonth = $faker->numberBetween(1, 12);
        $expirationYear = $faker->numberBetween(date('Y'), date('Y') + 10);
        $securityCode = $faker->numberBetween(100, 999);

        $card = new Card(
            $number,
            $expirationMonth,
            $expirationYear,
            $securityCode
        );
        $expectedArray = [
            'card' => [
                'number' => $number,
            ],
        ];
        $this->assertEquals($expectedArray, $card->toInstrumentArray());
    }

    public function testToPaymentInstrumentCardArray()
    {
        $faker = \Faker\Factory::create();

        $number = '4' . str_pad((string)$faker->numberBetween(0, 99999999999), 15, '0', STR_PAD_LEFT);
        $expirationMonth = $faker->numberBetween(1, 12);
        $expirationYear = $faker->numberBetween(date('Y'), date('Y') + 10);
        $securityCode = $faker->numberBetween(100, 999);

        $card = new Card(
            $number,
            $expirationMonth,
            $expirationYear,
            $securityCode
        );
        $expectedArray = [
            'card' => [
                'expirationYear' => $expirationYear,
                'expirationMonth' => $expirationMonth,
                'type' => '001',
            ],
        ];
        $this->assertEquals($expectedArray, $card->toPaymentInstrumentCardArray());
    }

    public function testToPaymentInstrumentCardArrayWithUnknownType()
    {
        $faker = \Faker\Factory::create();

        $number = '4' . str_pad((string)$faker->numberBetween(0, 99999999999), 11, '0', STR_PAD_LEFT);
        $expirationMonth = $faker->numberBetween(1, 12);
        $expirationYear = $faker->numberBetween(date('Y'), date('Y') + 10);
        $securityCode = $faker->numberBetween(100, 999);

        $card = new Card(
            $number,
            $expirationMonth,
            $expirationYear,
            $securityCode
        );
        $expectedArray = [
            'card' => [
                'expirationYear' => $expirationYear,
                'expirationMonth' => $expirationMonth,
                'type' => '',
            ],
        ];
        $this->assertEquals($expectedArray, $card->toPaymentInstrumentCardArray());
    }

    public function testShouldTwoDigitYearExpirationYear()
    {
        $faker = \Faker\Factory::create();
        $year = date('Y');

        $number = $faker->creditCardNumber;
        $expirationMonth = $faker->numberBetween(1, 12);
        $expirationYear = $faker->numberBetween($year % 100, ($year + 10) % 100);
        $securityCode = $faker->numberBetween(100, 999);

        $card = new Card(
            $number,
            $expirationMonth,
            $expirationYear,
            $securityCode
        );
        $expectedArray = [
            'paymentInformation' => [
                'card' => [
                    'expirationYear' => substr($year, 0, 2) . $expirationYear,
                    'number' => $number,
                    'securityCode' => $securityCode,
                    'expirationMonth' => $expirationMonth,
                ],
            ]
        ];
        $this->assertEquals($expectedArray, $card->toArray());
    }

    public function testShouldTwoDigitExpirationYearWithCenturyChange()
    {
        $faker = \Faker\Factory::create();
        $year = 2098;

        $number = $faker->creditCardNumber;
        $expirationMonth = $faker->numberBetween(1, 12);
        $expirationYear = $faker->numberBetween($year % 100, ($year + 5) % 100);
        $securityCode = $faker->numberBetween(100, 999);
        $expirationYear = 02;

        $card = new Card(
            $number,
            $expirationMonth,
            $expirationYear,
            $securityCode
        );
        $expectedArray = [
            'paymentInformation' => [
                'card' => [
                    'expirationYear' => 2102,
                    'number' => $number,
                    'securityCode' => $securityCode,
                    'expirationMonth' => $expirationMonth,
                ],
            ]
        ];
        $this->assertEquals($expectedArray, $card->toArray());
    }
}
