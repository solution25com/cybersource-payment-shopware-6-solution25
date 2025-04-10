<?php

namespace CyberSource\Shopware6\Test\Unit\Library;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Library\CyberSource;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;

class CyberSourceFactoryTest extends TestCase
{
    public function testCreateCyberSource()
    {
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);

        $cyberSourceFactory = new CyberSourceFactory();
        $response = $cyberSourceFactory->createCyberSource(EnvironmentUrl::TEST, $mockRequestSignatureContract);
        $this->assertInstanceOf(CyberSource::class, $response);
    }
}
