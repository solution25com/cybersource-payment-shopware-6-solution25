<?php

namespace CyberSource\Shopware6\Test\Unit\Library\RequestSignature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\String\UnicodeString;
use CyberSource\Shopware6\Library\RequestSignature\HTTP;
use CyberSource\Shopware6\Library\RequestSignature\Digest;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class HTTPTest extends TestCase
{
    public function testHashSignature()
    {
        $http = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');

        $reflectionClass = new \ReflectionClass(HTTP::class);
        $method = $reflectionClass->getMethod('hashSignature');
        $method->setAccessible(true);

        $response = 'keyid="accessKey",algorithm="HmacSHA256",headers="headerKeysString",signature="r1Oppm3Uc+cVwDcWzzLOaSHT6wdyfI5lYvyru1tb8Hc="'; // phpcs:ignore

        $result = $method->invoke($http, 'signatureString', 'headerKeysString');
        $this->assertInstanceOf(UnicodeString::class, $result);
        $this->assertEquals($response, $result->__toString());
    }

    public function testGetHeadersForGetMethod(): void
    {
        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $endpoint = '/endpoint';

        $http = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');

        $reflection = new \ReflectionClass(HTTP::class);
        $method = $reflection->getMethod('getSignatureWithoutPayload');
        $method->setAccessible(true);
        $signature = $method->invoke($http, 'get', '/endpoint');

        $expectedHeaders = [
            'host' => 'apitest.cybersource.com',
            'Content-Type' => 'application/json',
            'v-c-merchant-id' => 'orgId',
            'Date' => $dateTime->format(DATE_RFC7231),
            'signature' => $signature->toString(),
        ];

        $headers = $http->getHeadersForGetMethod($endpoint);
        $this->assertSame($expectedHeaders, $headers);
    }

    public function testGetHeadersForPostMethod(): void
    {
        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $endpoint = '/endpoint';
        $requestPayload = 'requestPayload';

        $http = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');

        $payloadDigest = Digest::generate($requestPayload);

        $reflection = new \ReflectionClass(HTTP::class);
        $method = $reflection->getMethod('getSignatureWithPayload');
        $method->setAccessible(true);
        $signature = $method->invoke($http, 'post', '/endpoint', $payloadDigest);

        $expectedHeaders = [
            'host' => 'apitest.cybersource.com',
            'Content-Type' => 'application/json',
            'v-c-merchant-id' => 'orgId',
            'Date' => $dateTime->format(DATE_RFC7231),
            'signature' => $signature->toString(),
            'digest' => sprintf("%s=%s", 'SHA-256', $payloadDigest),
        ];

        $headers = $http->getHeadersForPostMethod($endpoint, $requestPayload);

        $this->assertSame($expectedHeaders, $headers);
    }

    public function testGetHeadersForPutMethod(): void
    {
        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $endpoint = '/endpoint';
        $requestPayload = 'requestPayload';

        $http = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');

        $payloadDigest = Digest::generate($requestPayload);

        $reflection = new \ReflectionClass(HTTP::class);
        $method = $reflection->getMethod('getSignatureWithPayload');
        $method->setAccessible(true);
        $signature = $method->invoke($http, 'put', '/endpoint', $payloadDigest);

        $expectedHeaders = [
            'host' => 'apitest.cybersource.com',
            'Content-Type' => 'application/json',
            'v-c-merchant-id' => 'orgId',
            'Date' => $dateTime->format(DATE_RFC7231),
            'signature' => $signature->toString(),
            'digest' => sprintf("%s=%s", 'SHA-256', $payloadDigest),
        ];

        $headers = $http->getHeadersForPutMethod($endpoint, $requestPayload);

        $this->assertSame($expectedHeaders, $headers);
    }

    public function testGetHeadersForPatchMethod(): void
    {
        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $endpoint = '/endpoint';
        $requestPayload = 'requestPayload';

        $http = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');

        $payloadDigest = Digest::generate($requestPayload);

        $reflection = new \ReflectionClass(HTTP::class);
        $method = $reflection->getMethod('getSignatureWithPayload');
        $method->setAccessible(true);
        $signature = $method->invoke($http, 'patch', '/endpoint', $payloadDigest);

        $expectedHeaders = [
            'host' => 'apitest.cybersource.com',
            'Content-Type' => 'application/json',
            'v-c-merchant-id' => 'orgId',
            'Date' => $dateTime->format(DATE_RFC7231),
            'signature' => $signature->toString(),
            'digest' => sprintf("%s=%s", 'SHA-256', $payloadDigest),
        ];

        $headers = $http->getHeadersForPatchMethod($endpoint, $requestPayload);

        $this->assertSame($expectedHeaders, $headers);
    }

    public function testGetHeadersForDeleteMethod(): void
    {
        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $endpoint = '/endpoint';

        $http = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');

        $reflection = new \ReflectionClass(HTTP::class);
        $method = $reflection->getMethod('getSignatureWithoutPayload');
        $method->setAccessible(true);
        $signature = $method->invoke($http, 'delete', '/endpoint');

        $expectedHeaders = [
            'host' => 'apitest.cybersource.com',
            'Content-Type' => 'application/json',
            'v-c-merchant-id' => 'orgId',
            'Date' => $dateTime->format(DATE_RFC7231),
            'signature' => $signature->toString(),
        ];

        $headers = $http->getHeadersForDeleteMethod($endpoint);
        $this->assertSame($expectedHeaders, $headers);
    }
}
