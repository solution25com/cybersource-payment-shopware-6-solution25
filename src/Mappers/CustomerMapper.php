<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\BillTo;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\Country\CountryEntity;

class CustomerMapper
{
    public static function mapToBillTo(CustomerEntity $customer): BillTo
    {
        $billingAddress = $customer->getActiveBillingAddress();
        if (!$billingAddress instanceof CustomerAddressEntity) {
            return new BillTo(
                $customer->getFirstName() ?: 'Unknown',
                $customer->getLastName() ?: 'Unknown',
                'Unknown Street',
                'Unknown City',
                'Unknown',
                '00000',
                'US',
                $customer->getEmail() ?: 'no-email@example.com'
            );
        }
        $countryState = $billingAddress->getCountryState();
        $stateCode = ($countryState && strpos($countryState->getShortCode(), '-') !== false)
            ? substr($countryState->getShortCode(), strpos($countryState->getShortCode(), '-') + 1)
            : 'Unknown';
        $country = $billingAddress->getCountry();
        $countryIso = $country instanceof CountryEntity ? $country->getIso() ?: 'US' : 'US';
        return new BillTo(
            $customer->getFirstName() ?: 'Unknown',
            $customer->getLastName() ?: 'Unknown',
            $billingAddress->getStreet() ?: 'Unknown Street',
            $billingAddress->getCity() ?: 'Unknown City',
            $stateCode,
            $billingAddress->getZipCode() ?: '00000',
            $countryIso,
            $customer->getEmail() ?: 'no-email@example.com',
        );
    }
}
