<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Objects;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\BillTo;

class BillToTest extends TestCase
{
    public function testToArrayFromBillToClass()
    {
        $faker = \Faker\Factory::create();
        $firstName = $faker->firstName;
        $lastName = $faker->lastName;
        $address1 = $faker->streetAddress;
        $locality = $faker->city;
        $administrativeArea = $faker->state;
        $postalCode = $faker->postcode;
        $country = $faker->country;
        $email = $faker->email;

        $billTo = new BillTo(
            $firstName,
            $lastName,
            $address1,
            $locality,
            $administrativeArea,
            $postalCode,
            $country,
            $email
        );

        $expectedArray = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'address1' => $address1,
            'postalCode' => $postalCode,
            'locality' => $locality,
            'administrativeArea' => $administrativeArea,
            'country' => $country,
            'email' => $email
        ];

        $response = $billTo->toArray();
        $this->assertEquals($expectedArray, $response);
    }
}
