<?php

namespace CyberSource\Shopware6\Test\Unit\Library\RequestSignature;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Library\RequestSignature\Oauth;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class OauthTest extends TestCase
{
    public function testHashSignature()
    {
        $oauth = new Oauth(EnvironmentUrl::TEST, 'orgId', 'accessToken');

        $reflectionClass = new \ReflectionClass(Oauth::class);
        $method = $reflectionClass->getMethod('hashSignature');
        $method->setAccessible(true);

        $result = $method->invoke($oauth, 'signatureString', 'headerKeysString');
        $this->assertEquals('', $result);
    }
}
