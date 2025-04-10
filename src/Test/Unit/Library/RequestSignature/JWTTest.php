<?php

namespace CyberSource\Shopware6\Test\Unit\Library\RequestSignature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\String\UnicodeString;
use CyberSource\Shopware6\Library\RequestSignature\JWT;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class JWTTest extends TestCase
{
    public function testHashSignature()
    {
        $jwt = new JWT(EnvironmentUrl::TEST, 'orgId', 'p12');

        $reflectionClass = new \ReflectionClass(JWT::class);
        $method = $reflectionClass->getMethod('hashSignature');
        $method->setAccessible(true);

        $result = $method->invoke($jwt, 'signatureString', 'headerKeysString');
        $this->assertInstanceOf(UnicodeString::class, $result);
    }
}
