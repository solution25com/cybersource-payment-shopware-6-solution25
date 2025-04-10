<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Mappers;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Objects\BillTo;
use Shopware\Core\System\Country\CountryEntity;
use CyberSource\Shopware6\Mappers\CustomerMapper;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;

class CustomerMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function testMapToBillTo()
    {
        $faker = \Faker\Factory::create();
        $firstName = $faker->firstName();
        $lastName = $faker->lastName();
        $email = $faker->email();
        $stateCode = $faker->stateAbbr();
        $countryName = $faker->country();
        $street = $faker->streetAddress();
        $city = $faker->city();
        $zipcode = $faker->postcode();
        $iso = $faker->iso8601();


        $mockCustomerEntity = $this->createMock(CustomerEntity::class);

        $billingAddress = $this->createMock(CustomerAddressEntity::class);
        $countryStateEntity = $this->createMock(CountryStateEntity::class);
        $countryEntity = $this->createMock(CountryEntity::class);

        $mockCustomerEntity->method('getFirstName')->willReturn($firstName);
        $mockCustomerEntity->method('getLastName')->willReturn($lastName);
        $mockCustomerEntity->method('getEmail')->willReturn($email);
        $mockCustomerEntity->method('getActiveBillingAddress')->willReturn($billingAddress);
        $billingAddress->method('getStreet')->willReturn($street);
        $billingAddress->method('getCity')->willReturn($city);
        $billingAddress->method('getCountryState')->willReturn($countryStateEntity);
        $billingAddress->method('getCountry')->willReturn($countryEntity);
        $billingAddress->method('getZipCode')->willReturn($zipcode);

        $countryStateEntity->method('getShortCode')->willReturn($faker->countryCode() . "-" . $stateCode);
        $countryEntity->method('getIso')->willReturn($iso);

        $expectedCustomerEntity = new BillTo(
            $firstName,
            $lastName,
            $street,
            $city,
            $stateCode,
            $zipcode,
            $iso,
            $email
        );

        $customerEntity = CustomerMapper::mapToBillTo($mockCustomerEntity);
        $this->assertEquals($expectedCustomerEntity, $customerEntity);
    }

    public function testMapToBillToWithStateCodeEmpty()
    {
        $faker = \Faker\Factory::create();
        $firstName = $faker->firstName();
        $lastName = $faker->lastName();
        $email = $faker->email();
        $stateCode = '';
        $countryName = $faker->country();
        $street = $faker->streetAddress();
        $city = $faker->city();
        $zipcode = $faker->postcode();
        $iso = $faker->iso8601();


        $mockCustomerEntity = $this->createMock(CustomerEntity::class);

        $billingAddress = $this->createMock(CustomerAddressEntity::class);
        $countryStateEntity = $this->createMock(CountryStateEntity::class);
        $countryEntity = $this->createMock(CountryEntity::class);

        $mockCustomerEntity->method('getFirstName')->willReturn($firstName);
        $mockCustomerEntity->method('getLastName')->willReturn($lastName);
        $mockCustomerEntity->method('getEmail')->willReturn($email);
        $mockCustomerEntity->method('getActiveBillingAddress')->willReturn($billingAddress);
        // $mockActiveBillingAddress = $mockCustomerEntity->method('getActiveBillingAddress')->willReturn('');
        $billingAddress->method('getStreet')->willReturn($street);
        $billingAddress->method('getCity')->willReturn($city);
        $billingAddress->method('getCountryState')->willReturn($countryStateEntity);
        $billingAddress->method('getCountry')->willReturn($countryEntity);
        $billingAddress->method('getZipCode')->willReturn($zipcode);

        $countryStateEntity->method('getShortCode')->willReturn('');
        $countryEntity->method('getIso')->willReturn($iso);

        $expectedCustomerEntity = new BillTo(
            $firstName,
            $lastName,
            $street,
            $city,
            $stateCode,
            $zipcode,
            $iso,
            $email
        );

        $customerEntity = CustomerMapper::mapToBillTo($mockCustomerEntity);
        $this->assertEquals($expectedCustomerEntity, $customerEntity);
    }
}
