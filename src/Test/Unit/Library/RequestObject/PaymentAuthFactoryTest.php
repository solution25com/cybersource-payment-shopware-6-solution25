<?php

namespace CyberSource\Shopware6\Test\Unit\Library\RequestObject;

use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuth;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuthFactory;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;

class PaymentAuthFactoryTest extends TestCase
{
    public function testCreatePaymentAuth()
    {
        $order = \Mockery::mock(OrderEntity::class, function (MockInterface $mock) {
            $mock->shouldReceive('getOrderNumber')->andReturn('12345');
            $mock->shouldReceive('getAmountTotal')->andReturn('12345');
            $mock->shouldReceive('getCurrency')->andReturnUsing(function () {
                $currencyEntity = \Mockery::mock('Shopware\Core\System\Currency\CurrencyEntity');
                $currencyEntity->shouldReceive('getIsoCode')->andReturn('USD');
                return $currencyEntity;
            });
        });
        $mockLineItemsEntity = \Mockery::mock(OrderLineItemCollection::class);
        $mockLineItemsEntity->shouldReceive("getElements")->andReturn([]);
        $order->shouldReceive("getLineItems")->andReturn($mockLineItemsEntity);

        $customer = \Mockery::mock(CustomerEntity::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEmail')->andReturn('test@example.com');
            $mock->shouldReceive('getFirstName')->andReturn('test');
            $mock->shouldReceive('getLastName')->andReturn('test');
            $mock->shouldReceive('getActiveBillingAddress')->andReturnUsing(function () {
                $customerAddressEntity = \Mockery::mock(
                    'Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity'
                );
                $customerAddressEntity->shouldReceive('getStreet')->andReturn('USD');
                $customerAddressEntity->shouldReceive('getCity')->andReturn('USD');
                $customerAddressEntity->shouldReceive('getCountryState')->andReturnUsing(function () {
                    $countryStateEntity = \Mockery::mock(
                        'Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity'
                    );
                    $countryStateEntity->shouldReceive('getShortCode')->andReturn('US-IL');
                    return $countryStateEntity;
                });
                $customerAddressEntity->shouldReceive('getZipcode')->andReturn('USD');
                $customerAddressEntity->shouldReceive('getCountry')->andReturnUsing(function () {
                    $countryEntity = \Mockery::mock('Shopware\Core\System\Country\CountryEntity');
                    $countryEntity->shouldReceive('getIso')->andReturn('USD');
                    return $countryEntity;
                });
                return $customerAddressEntity;
            });
        });

        $cardInformation = [
            'cardNumber' => '4111111111111111',
            'expirationDate' => '12/25',
            'securityCode' => '123',
            'paymentInstrument' => '1222',
        ];

        $paymentAuthFactory = new PaymentAuthFactory();

        $result = $paymentAuthFactory->createPaymentAuth($order, $customer, $cardInformation, true);
        $this->assertInstanceOf(PaymentAuth::class, $result);
    }
}
