<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Mappers;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use CyberSource\Shopware6\Objects\ClientReference;
use CyberSource\Shopware6\Mappers\OrderClientReferenceMapper;

class OrderClientReferenceMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function testMapToClientReference()
    {
        $faker = \Faker\Factory::create();
        $orderId = (string) $faker->randomNumber(6, true);


        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method("getOrderNumber")->willReturn($orderId);

        $orderClientReferenceMapper = new OrderClientReferenceMapper();
        $expectedOrderClientReferenceMapper = new ClientReference($orderId);
        $customerEntity = $orderClientReferenceMapper::mapToClientReference($mockOrderEntity);
        $this->assertEquals($expectedOrderClientReferenceMapper, $customerEntity);
    }
}
