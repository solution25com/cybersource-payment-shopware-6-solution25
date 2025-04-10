<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Objects;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\ClientReference;

class ClientReferenceTest extends TestCase
{
    public function testToArrayFromClientReferenceClass()
    {
        $faker = \Faker\Factory::create();

        $code = $faker->regexify('[A-Za-z]{10}');

        $clientReference = new ClientReference(
            $code
        );
        $expectedArray = [
            'clientReferenceInformation' => [
                    'code' => $code
            ]
        ];
        $this->assertEquals($expectedArray, $clientReference->toArray());
    }
}
