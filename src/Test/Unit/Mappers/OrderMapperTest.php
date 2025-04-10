<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Mappers;

use Mockery;
use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\Order;
use CyberSource\Shopware6\Objects\BillTo;
use Shopware\Core\Checkout\Order\OrderEntity;
use CyberSource\Shopware6\Mappers\OrderMapper;
use Shopware\Core\System\Country\CountryEntity;
use CyberSource\Shopware6\Mappers\CustomerMapper;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;

class OrderMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testMapToOrder()
    {
        $faker = \Faker\Factory::create();
        $totalAmount = $faker->randomFloat(2);
        $orderId = (string) $faker->randomNumber(6, true);
        $currencyCode = 'US';

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockCurrencyEntity = $this->createMock(CurrencyEntity::class);
        $mockCurrencyEntity->method("getIsoCode")->willReturn('US');
        $mockOrderEntity->method("getAmountTotal")->willReturn($totalAmount);
        $mockOrderEntity->method("getCurrency")->willReturn($mockCurrencyEntity);

        $mockLineItemsEntity = $this->createMock(OrderLineItemCollection::class);
        $mockOrderEntity->method("getLineItems")->willReturn($mockLineItemsEntity);

        $mockedBillTo = $this->getMockBuilder(BillTo::class)
            ->disableOriginalConstructor()
            ->getMock();

         $objBillTo = new BillTo(
             'John',
             'Doe',
             '123 Main St',
             'New York',
             'IL',
             '10001',
             'US',
             'john.doe@example.com',
             '123-456-7890',
         );

        $customerMapperMock = $this->createMock(CustomerMapper::class);
        $customerMapperMock->method('mapToBillTo')->willReturn($mockedBillTo);

        $orderMapper = new OrderMapper();
        $expectedOrder = new Order(
            $totalAmount,
            $currencyCode,
            $objBillTo,
            []
        );

        $mockCustomerEntity = $mockFollowup = \Mockery::mock(CustomerEntity::class);

        $addressMock = \Mockery::mock(CustomerAddressEntity::class);
        $countryStateMock = \Mockery::mock(CountryStateEntity::class);

        $countryMock = \Mockery::mock(CountryEntity::class);

        $mockCustomerEntity->shouldReceive([
            'getFirstName' => 'John',
            'getLastName' => 'Doe',
            'getEmail' => 'john.doe@example.com',
            'getActiveBillingAddress' => $addressMock,
            'getStreet' => 'John',
        ]);

        $countryStateMock->shouldReceive([
            'getShortCode' => 'US-IL',
        ]);

        $countryMock->shouldReceive([
            'getIso' => 'US',
        ]);

        $addressMock->shouldReceive([
            'getStreet' => '123 Main St',
            'getCity' => 'New York',
            'getCountryState' => $countryStateMock,
            'getZipCode' => '10001',
            'getCountry' => $countryMock,
            'getPhoneNumber' => '123-456-7890',
        ]);

        $response = $orderMapper::mapToOrder($mockOrderEntity, $mockCustomerEntity);

        $this->assertEquals($expectedOrder, $response);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
