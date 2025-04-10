<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Objects;

class BillTo
{
    private readonly string $firstName;
    private readonly string $lastName;
    private readonly string $address1;
    private readonly string $locality;
    private readonly string $administrativeArea;
    private readonly string $postalCode;
    private readonly string $country;
    private readonly string $email;

    public function __construct(
        string $firstName,
        string $lastName,
        string $address1,
        string $locality,
        string $administrativeArea,
        string $postalCode,
        string $country,
        string $email
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->address1 = $address1;
        $this->locality = $locality;
        $this->administrativeArea = $administrativeArea;
        $this->postalCode = $postalCode;
        $this->country = $country;
        $this->email = $email;
    }

    /**
     * Returns the billing data as an array.
     * Note: Cybersource does not support empty or null values and excludes them.
     *
     * @return array
     */
    public function toArray(): array
    {
        $billingData = [
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'address1' => $this->address1,
            'postalCode' => $this->postalCode,
            'locality' => $this->locality,
            'administrativeArea' => $this->administrativeArea,
            'country' => $this->country,
            'email' => $this->email,
        ];

        $filteredBillingData = array_filter($billingData, function ($value) {
            return !empty($value) && $value !== null;
        });

        return $filteredBillingData;
    }
}
