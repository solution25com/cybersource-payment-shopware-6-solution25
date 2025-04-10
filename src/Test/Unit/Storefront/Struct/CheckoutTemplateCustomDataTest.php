<?php

namespace CyberSource\Shopware6\Test\Unit\Storefront\Struct;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Storefront\Struct\CheckoutTemplateCustomData;

class CheckoutTemplateCustomDataTest extends TestCase
{
    public function testGetExtensionNameConst()
    {
        $this->assertEquals("cybersource_shopware6", CheckoutTemplateCustomData::EXTENSION_NAME);
    }
}
