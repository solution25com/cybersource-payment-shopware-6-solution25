<?php

namespace CyberSource\Shopware6\Library\RequestObject;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;

class PaymentAuthFactory
{
    public function createPaymentAuth(
        OrderEntity $order,
        CustomerEntity $customer,
        array $cardInformation,
        bool $isSavedCardChecked
    ): PaymentAuth {
        return new PaymentAuth($order, $customer, $cardInformation, $isSavedCardChecked);
    }
}
