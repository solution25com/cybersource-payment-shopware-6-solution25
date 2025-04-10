<?php

namespace CyberSource\Shopware6\Test\Unit\Library\RequestSignature;

use CyberSource\Shopware6\Library\RequestSignature\Digest;
use PHPUnit\Framework\TestCase;

class DigestTest extends TestCase
{
    public function testGenerate()
    {
        $requestPayload = 'data';
        $expectedDigest = base64_encode(hash('sha256', $requestPayload, true));

        $result = Digest::generate($requestPayload);

        $this->assertEquals($expectedDigest, $result);
    }
}
