<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Library;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Library\RestClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use CyberSource\Shopware6\Library\RequestSignature\HTTP;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class RestClientTest extends TestCase
{
    public function testGetData()
    {
        $headerSignature = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');
        $scopingHttpClient = $this->createMock(HttpClientInterface::class);

        $restClient = new RestClient('https://example.com', $headerSignature);
        $reflection = new \ReflectionClass($restClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($restClient, $scopingHttpClient);

        $result = $restClient->getData('/endpoint');

        $this->assertEquals([], $result);
    }

    public function testPostData()
    {
        $headerSignature = new HTTP(EnvironmentUrl::TEST, 'orgId', 'accessKey', 'secretKey');
        $scopingHttpClient = $this->createMock(HttpClientInterface::class);

        $restClient = new RestClient('https://example.com', $headerSignature);
        $reflection = new \ReflectionClass($restClient);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($restClient, $scopingHttpClient);

        $result = $restClient->postData('/endpoint', []);

        $this->assertEquals([], $result);
    }
}
