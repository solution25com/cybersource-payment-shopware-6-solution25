<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use CyberSource\Shopware6\Library\RequestSignature\HTTP;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class ConfigurationServiceTest extends TestCase
{
    private ConfigurationService $configurationService;
    private SystemConfigService $systemConfigService;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemConfigService = $this->getMockBuilder(SystemConfigService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->translator = $this->getMockBuilder(TranslatorInterface::class)->getMock();
    }

    /**
     * The function tests the `getAccessKey` method of the `ConfigurationService` class by mocking the
     * `isProductionActive` and `get` methods and asserting that the expected access key is returned.
     */
    public function testGetAccessKey(): void
    {
        $expectedAccesskey = rand();

        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
        ->onlyMethods(['isProductionActive', 'get'])
        ->disableOriginalConstructor()
        ->getMock();

        $mockConfigurationService->expects($this->any())
            ->method('isProductionActive')
            ->willReturn("1");
        $mockConfigurationService->expects($this->any())
            ->method('get')
            ->willReturn($expectedAccesskey);

        $actualAccessKey = $mockConfigurationService->getAccessKey();

        $this->assertEquals($expectedAccesskey, $actualAccessKey);
    }

    /**
     * The function tests the `getSecretKey` method of the `ConfigurationService` class by mocking the
     * `isProductionActive` and `get` methods and asserting that the expected secret key is returned.
     */
    public function testGetSecretKey(): void
    {
        $expectedSecretKey = rand();
        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
        ->onlyMethods(['isProductionActive', 'get'])
        ->disableOriginalConstructor()
        ->getMock();

        $mockConfigurationService->expects($this->any())
            ->method('isProductionActive')
            ->willReturn("1");
        $mockConfigurationService->expects($this->any())
            ->method('get')
            ->willReturn($expectedSecretKey);

        $actualSecretKey = $mockConfigurationService->getSecretKey();

        $this->assertEquals($expectedSecretKey, $actualSecretKey);
    }

    /**
     * The function `testGetOrganizationID` tests the `getOrganizationID` method of the
     * `ConfigurationService` class by mocking the `ConfigurationService` and asserting that the
     * expected organization ID is returned.
     */
    public function testGetOrganizationID(): void
    {
        $expectedOrganizationID = rand();
        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
        ->onlyMethods(['isProductionActive', 'get'])
        ->disableOriginalConstructor()
        ->getMock();

        $mockConfigurationService->expects($this->any())
            ->method('isProductionActive')
            ->willReturn("1");
        $mockConfigurationService->expects($this->any())
            ->method('get')
            ->willReturn($expectedOrganizationID);

        $actualOrganizationID = $mockConfigurationService->getOrganizationID();

        $this->assertEquals($expectedOrganizationID, $actualOrganizationID);
    }

    /**
     * The testGetAccessTokenMethod function tests the getAccessToken method of the
     * ConfigurationService class in PHP.
     */
    public function testGetAccessTokenMethod(): void
    {
        $configurationService = new ConfigurationService($this->systemConfigService, $this->translator);

        $accessKey = $configurationService->getAccessToken();

        $this->assertEquals('', $accessKey);
    }

    /**
     * The testGetP12Method function tests the getP12 method of the ConfigurationService class.
     */
    public function testGetP12Method(): void
    {
        $configurationService = new ConfigurationService($this->systemConfigService, $this->translator);

        $p12Value = $configurationService->getP12();

        $this->assertEquals('', $p12Value);
    }

    /**
     * The function tests the `getBaseUrl()` method of the `ConfigurationService` class when a sandbox
     * account is set, and asserts that the returned base URL is correct.
     */
    public function testGetBaseUrlWhenSanboxAccountSet(): void
    {
        $configurationService = new ConfigurationService($this->systemConfigService, $this->translator);

        $baseUrl = $configurationService->getBaseUrl();
        $this->assertEquals('TEST', $baseUrl->name);
        $this->assertEquals('https://apitest.cybersource.com/', $baseUrl->value);
    }

    /**
     * The function `testGetBaseUrlWhenProductionAccountSet` tests the `getBaseUrl` method of the
     * `ConfigurationService` class when the production account is set.
     */
    public function testGetBaseUrlWhenProductionAccountSet(): void
    {
        $this->systemConfigService->expects($this->any())
                ->method('get')
                ->withConsecutive(
                    ['CyberSourceShopware6.config.isProductionActive', null]
                )
                ->willReturn(1);

        $configurationService = new ConfigurationService($this->systemConfigService, $this->translator);

        $baseUrl = $configurationService->getBaseUrl();
        $this->assertEquals('PRODUCTION', $baseUrl->name);
        $this->assertEquals('https://api.cybersource.com/', $baseUrl->value);
    }

    /**
     * The function `testGetJWTRequestSignatureThrowsRuntimeException` tests that the
     * `getJWTRequestSignature` method of the `ConfigurationService` class throws a `RuntimeException`
     * with the expected error message when JWT is not supported.
     */
    public function testGetJWTRequestSignatureThrowsRuntimeException(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->expects($this->once())
            ->method('trans')
            ->with('cybersource_shopware6.request-signature.JWTNotSupported')
            ->willReturn('JWT is not supported in the current implementation');

        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
                                ->setConstructorArgs([$this->systemConfigService, $this->translator])
                                ->getMock();

        $reflectionMethod = new \ReflectionMethod(ConfigurationService::class, 'getJWTRequestSignature');
        $reflectionMethod->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT is not supported in the current implementation');
        $reflectionMethod->invoke($mockConfigurationService);
    }

    /**
     * The function `testGetOauthRequestSignatureThrowsRuntimeException` tests that the
     * `getOauthRequestSignature` method of the `ConfigurationService` class throws a
     * `RuntimeException` with the expected error message when OAuth is not supported.
     */
    public function testGetOauthRequestSignatureThrowsRuntimeException(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->expects($this->once())
            ->method('trans')
            ->with('cybersource_shopware6.request-signature.OauthNotSupported')
            ->willReturn('OAuth is not supported in the current implementation');

        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
                                ->setConstructorArgs([$this->systemConfigService, $this->translator])
                                ->getMock();

        $reflectionMethod = new \ReflectionMethod(ConfigurationService::class, 'getOauthRequestSignature');
        $reflectionMethod->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OAuth is not supported in the current implementation');
        $reflectionMethod->invoke($mockConfigurationService);
    }

    /**
     * The function tests the `getHTTPRequestSignature` method of the `ConfigurationService` class in
     * PHP.
     */
    public function testGetHTTPRequestSignature(): void
    {
        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
            ->onlyMethods(['getAccessKey', 'getSecretKey', 'getOrganizationID', 'getBaseUrl'])
            ->disableOriginalConstructor()
            ->getMock();

        $mockConfigurationService->expects($this->once())
            ->method('getAccessKey')
            ->willReturn((string) rand());
        $mockConfigurationService->expects($this->once())
            ->method('getSecretKey')
            ->willReturn((string) rand());
        $mockConfigurationService->expects($this->once())
            ->method('getOrganizationID')
            ->willReturn((string) rand());
        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $httpRequestSignature = $mockConfigurationService->getHTTPRequestSignature();

        $this->assertInstanceOf(HTTP::class, $httpRequestSignature);
    }

    /**
     * The function `testGetSignatureContract` creates a mock object of `ConfigurationService` and
     * tests if the returned object is an instance of `HTTP`.
     */
    public function testGetSignatureContract(): void
    {
        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
            ->onlyMethods(['getAccessKey', 'getSecretKey', 'getOrganizationID', 'getBaseUrl'])
            ->disableOriginalConstructor()
            ->getMock();

        $mockConfigurationService->expects($this->once())
            ->method('getAccessKey')
            ->willReturn((string) rand());
        $mockConfigurationService->expects($this->once())
            ->method('getSecretKey')
            ->willReturn((string) rand());
        $mockConfigurationService->expects($this->once())
            ->method('getOrganizationID')
            ->willReturn((string) rand());
        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $httpRequestSignature = $mockConfigurationService->getSignatureContract();

        $this->assertInstanceOf(HTTP::class, $httpRequestSignature);
    }

    /**
     * The function `testGetTransactionType` tests the `getTransactionType` method of the
     * `ConfigurationService` class by mocking the `ConfigurationService` and asserting that the
     * expected payment step is returned.
     */
    public function testGetTransactionType(): void
    {
        $expectedTransactionType = 'auth';
        $mockConfigurationService = $this->getMockBuilder(ConfigurationService::class)
        ->onlyMethods(['get'])
        ->disableOriginalConstructor()
        ->getMock();

        $mockConfigurationService->expects($this->any())
            ->method('get')
            ->willReturn($expectedTransactionType);

        $actualTransactionType = $mockConfigurationService->getTransactionType();

        $this->assertEquals($expectedTransactionType, $actualTransactionType);
    }
}
