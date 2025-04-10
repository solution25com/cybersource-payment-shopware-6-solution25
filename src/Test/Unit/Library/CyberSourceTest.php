<?php

namespace CyberSource\Shopware6\Test\Unit\Library;

use Mockery;
use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Library\RestClient;
use CyberSource\Shopware6\Library\CyberSource;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuth;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;

class CyberSourceTest extends TestCase
{
    private $faker;
    private array $paymentResponseData;

    protected function setUp(): void
    {
        $this->faker = \Faker\Factory::create();

        $this->paymentResponseData = [
            "clientReferenceInformation" => [
                "code" => $this->faker->randomNumber(5),
            ],
            "orderInformation" => [
                "amountDetails" => [
                    "totalAmount" => $this->faker->randomFloat,
                    "currency" => $this->faker->currencyCode,
                ],
            ],
            "paymentInformation" => [
                "card" => [
                    "expirationYear" => $this->faker->year('+9 years'),
                    "number" => $this->faker->creditCardNumber,
                    "securityCode" => $this->faker->randomNumber(3),
                    "expirationMonth" => 12,
                ],
            ],
            "id" => "ghp_6606192211041"
        ];
    }

    public function testAuthAndCapturePaymentWithCreditCardSuccess()
    {
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $paymentAuthMock = $this->createMock(PaymentAuth::class);

        $cyberSource = new CyberSource(
            EnvironmentUrl::TEST,
            $mockRequestSignatureContract
        );

        $apiClientMock = $this->getMockBuilder(RestClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['postData'])
            ->getMock();

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($cyberSource, $apiClientMock);

        $expectedResponse = [
            'status' => 'AUTHORIZED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->expects($this->any())
            ->method('postData')
            ->willReturn($expectedResponse);

        $result = $cyberSource->authAndCapturePaymentWithCreditCard($paymentAuthMock);
        $this->assertSame($expectedResponse, $result);
    }

    public function testAuthAndCapturePaymentWithCreditCardUnAuthorized()
    {
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $paymentAuthMock = $this->createMock(PaymentAuth::class);

        $cyberSource = new CyberSource(
            EnvironmentUrl::TEST,
            $mockRequestSignatureContract
        );

        $apiClientMock = $this->getMockBuilder(RestClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['postData'])
            ->getMock();

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($cyberSource, $apiClientMock);

        $expectedResponse = [
            'status' => 'PENDING_REVIEW_PROFILE',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->expects($this->any())
            ->method('postData')
            ->willReturn($expectedResponse);

        $result = $cyberSource->authAndCapturePaymentWithCreditCard($paymentAuthMock);
        $this->assertSame($expectedResponse, $result);
    }

    public function testAuthAndCapturePaymentWithCreditCardException()
    {
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockcybersource->shouldAllowMockingProtectedMethods();
        $paymentAuthMock = $this->createMock(PaymentAuth::class);

        $responseFromAuthorized = [
            'status' => 'AUTHORIZED',
            'id' => 'ghp_6606192211041'
        ];

        $class = new \ReflectionClass(CyberSource::class);
        $method = $class->getMethod('doAuthorizationReversal');
        $method->setAccessible(true);

        $mockcybersource
            ->shouldReceive('authorizePaymentWithCreditCard')
            ->andReturn($responseFromAuthorized);

        $mockcybersource
            ->shouldReceive('capturePaymentWithCreditCard')
            ->andThrow(new \Exception('Authorization System or issuer system inoperative'));

        $mockcybersource
            ->shouldReceive('doAuthorizationReversal')
            ->andReturn([]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Authorization System or issuer system inoperative');

        $method = self::getMethod('authAndCapturePaymentWithCreditCard');
        $method->invokeArgs($mockcybersource, [$paymentAuthMock]);
    }

    public function testdoAuthorizationReversal()
    {
        $transactionId = $this->paymentResponseData['id'];
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockRequestSignatureContract = Mockery::mock(RequestSignatureContract::class);
        $paymentAuthMock = Mockery::mock(PaymentAuth::class);
        $apiClientMock = Mockery::mock(RestClient::class);

        $paymentAuthMock->shouldReceive('makeAuthReversalPaymentRequest')
            ->once()
            ->andReturn([
                'status' => 'REVERSED',
                'id' => 'ghp_6606192211041'
            ]);

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($mockcybersource, $apiClientMock);

        $expectedResponse = [
            'status' => 'REVERSED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->shouldReceive('postData')
            ->once()
            ->andReturn($expectedResponse);

        $method = self::getMethod('doAuthorizationReversal');
        $result = $method->invokeArgs($mockcybersource, [$paymentAuthMock, $transactionId]);
        $this->assertSame($result, $expectedResponse);
    }

    public function testCreateCybersourceCustomer()
    {
        $transactionId = $this->paymentResponseData['id'];
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockRequestSignatureContract = Mockery::mock(RequestSignatureContract::class);
        $paymentAuthMock = Mockery::mock(PaymentAuth::class);
        $apiClientMock = Mockery::mock(RestClient::class);

        $paymentAuthMock->shouldReceive('getCybersourceCustomerRequestPayload')
            ->once()
            ->andReturn([
                'status' => 'REVERSED',
                'id' => 'ghp_6606192211041'
            ]);

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($mockcybersource, $apiClientMock);

        $expectedResponse = [
            'status' => 'REVERSED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->shouldReceive('postData')
            ->once()
            ->andReturn($expectedResponse);

        $method = self::getMethod('createCybersourceCustomer');
        $result = $method->invokeArgs($mockcybersource, [$paymentAuthMock, "1234", "abc@gmail.com"]);
        $this->assertSame($result, $expectedResponse);
    }

    public function testCreatePaymentInstrument()
    {
        $transactionId = $this->paymentResponseData['id'];
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockRequestSignatureContract = Mockery::mock(RequestSignatureContract::class);
        $paymentAuthMock = Mockery::mock(PaymentAuth::class);
        $apiClientMock = Mockery::mock(RestClient::class);

        $paymentAuthMock->shouldReceive('getPaymentInstrumentRequestPayload')
            ->once()
            ->andReturn([
                'status' => 'REVERSED',
                'id' => 'ghp_6606192211041'
            ]);

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($mockcybersource, $apiClientMock);

        $expectedResponse = [
            'status' => 'REVERSED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->shouldReceive('postData')
            ->once()
            ->andReturn($expectedResponse);

        $method = self::getMethod('createPaymentInstrument');
        $result = $method->invokeArgs($mockcybersource, [$paymentAuthMock, "1234", "1234"]);
        $this->assertSame($result, $expectedResponse);
    }

    public function testGenerateInstrumentIdentifier()
    {
        $transactionId = $this->paymentResponseData['id'];
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockRequestSignatureContract = Mockery::mock(RequestSignatureContract::class);
        $paymentAuthMock = Mockery::mock(PaymentAuth::class);
        $apiClientMock = Mockery::mock(RestClient::class);

        $paymentAuthMock->shouldReceive('makeInstrumentIdentifierRequest')
            ->once()
            ->andReturn([
                'status' => 'REVERSED',
                'id' => 'ghp_6606192211041'
            ]);

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($mockcybersource, $apiClientMock);

        $expectedResponse = [
            'status' => 'REVERSED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->shouldReceive('postData')
            ->once()
            ->andReturn($expectedResponse);

        $method = self::getMethod('generateInstrumentIdentifier');
        $result = $method->invokeArgs($mockcybersource, [$paymentAuthMock, "1234", "1234"]);
        $this->assertSame($result, $expectedResponse);
    }

    public function testRefundPayment()
    {
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockRequestSignatureContract = Mockery::mock(RequestSignatureContract::class);
        $apiClientMock = Mockery::mock(RestClient::class);

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($mockcybersource, $apiClientMock);

        $expectedResponse = [
            'status' => 'REVERSED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->shouldReceive('postData')
            ->once()
            ->andReturn($expectedResponse);

        $method = self::getMethod('refundPayment');
        $result = $method->invokeArgs($mockcybersource, ["1234", $this->paymentResponseData]);
        $this->assertSame($result, $expectedResponse);
    }

    public function testCapturePayment()
    {
        $mockcybersource = Mockery::mock(CyberSource::class);
        $mockRequestSignatureContract = Mockery::mock(RequestSignatureContract::class);
        $apiClientMock = Mockery::mock(RestClient::class);

        $reflectionClass = new \ReflectionClass(CyberSource::class);
        $apiClientProperty = $reflectionClass->getProperty('apiClient');
        $apiClientProperty->setAccessible(true);
        $apiClientProperty->setValue($mockcybersource, $apiClientMock);

        $expectedResponse = [
            'status' => 'REVERSED',
            'id' => 'ghp_6606192211041'
        ];

        $apiClientMock->shouldReceive('postData')
            ->once()
            ->andReturn($expectedResponse);

        $method = self::getMethod('capturePayment');
        $result = $method->invokeArgs($mockcybersource, ["1234", $this->paymentResponseData]);
        $this->assertSame($result, $expectedResponse);
    }

    protected static function getMethod($name)
    {
        $class = new \ReflectionClass(CyberSource::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method;
    }
}
