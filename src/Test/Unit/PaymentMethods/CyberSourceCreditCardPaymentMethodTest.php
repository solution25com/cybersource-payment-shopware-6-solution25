<?php

namespace CyberSource\Shopware6\Test\Unit\PaymentMethods;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Gateways\CreditCard;
use CyberSource\Shopware6\PaymentMethods\CyberSourceCreditCardPaymentMethod;

class CyberSourceCreditCardPaymentMethodTest extends TestCase
{
    public function testCyberSourceCreditCardPaymentMethods()
    {
        $classObject = new CyberSourceCreditCardPaymentMethod();

        $this->assertEquals('CyberSourceCreditCard', $classObject->getName());
        $this->assertEquals('CyberSource Payment', $classObject->getDescription());
        $this->assertEquals(CreditCard::class, $classObject->getPaymentHandler());
        $this->assertEquals('GATEWAY_CYBERSOURCE_CREDITCARD', $classObject->getGatewayCode());
        $this->assertEquals(null, $classObject->getTemplate());
        $this->assertEquals('', $classObject->getLogo());
        $this->assertEquals('', $classObject->getType());
    }
}
