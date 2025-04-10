<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Objects;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\Order;
use CyberSource\Shopware6\Objects\BillTo;

class OrderTest extends TestCase
{
    public function testToArrayFromOrderClass()
    {
        $faker = \Faker\Factory::create();
        $totalAmount = $faker->randomFloat(2);
        $currency = $faker->currencyCode;
        $firstName = $faker->firstName();
        $lastName = $faker->lastName();
        $email = $faker->email();
        $countryCode = $faker->countryCode();
        $countryName = $faker->country();
        $street = $faker->streetAddress();
        $city = $faker->city();
        $zipcode = $faker->postcode();
        $iso = $faker->iso8601();

        $lineItems[] = array(
                    'number' => 1,
                    'productName' => 'Product1',
                    'productCode' => 'P001',
                    'productSku' => 'P001',
                    'unitPrice' => '99',
                    'totalAmount' => '99',
                    'quantity' => 1,
                    'taxAmount' => 0,
                );

        $billTo =  new BillTo(
            $firstName,
            $lastName,
            $street,
            $city,
            $countryCode,
            $zipcode,
            $iso,
            $email
        );

        $order = new Order(
            $totalAmount,
            $currency,
            $billTo,
            $lineItems
        );

        $expectedArray = [
            'orderInformation' => [
                'billTo' => $billTo->toArray(),
                'amountDetails' => [
                    'totalAmount' => $totalAmount,
                    'currency' => $currency,
                ],
                'lineItems' => $lineItems,
            ]
        ];

        $this->assertEquals($expectedArray, $order->toArray());
    }
}
