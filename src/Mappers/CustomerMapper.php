<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\BillTo;
use Shopware\Core\Checkout\Customer\CustomerEntity;

class CustomerMapper
{
    public static function mapToBillTo(CustomerEntity $customer): BillTo
    {
        $billingAddress = $customer->getActiveBillingAddress();
        $countryState = $billingAddress ? $billingAddress->getCountryState() : null;

        // The short code format is typically "CountryCode-StateCode", e.g., "US-IL"
        // We need to extract only the state code part, so we split the short code by the hyphen ('-')
        // The state code will be the second part after splitting
        $stateCode = ($countryState && strpos($countryState->getShortCode(), '-') !== false)
            ? substr($countryState->getShortCode(), strpos($countryState->getShortCode(), '-') + 1)
            : '';

        return new BillTo(
            $customer->getFirstName(),
            $customer->getLastName(),
            $billingAddress->getStreet(),
            $billingAddress->getCity(),
            $stateCode,
            $billingAddress->getZipCode(),
            $billingAddress->getCountry()->getIso(),
            $customer->getEmail(),
        );
    }
}
