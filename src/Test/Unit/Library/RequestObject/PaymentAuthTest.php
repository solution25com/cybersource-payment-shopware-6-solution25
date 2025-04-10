<?php

namespace CyberSource\Shopware6\Test\Unit\Library\RequestObject;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuth;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;

class PaymentAuthTest extends TestCase
{
    private bool $isSavedCardChecked = true;

    private array $cardInformation = [
        'cardNumber' => '4111111111111111',
        'expirationDate' => '12/25',
        'securityCode' => '123',
        'paymentInstrument' => '1222',
    ];

    private array $paymentResponseData = [
        "clientReferenceInformation" => [
            "code" => 12345,
        ],
        "orderInformation" => [
            "billTo" => [
                "firstName" => "firstName",
                "lastName" => "lastName",
                "address1" => "street",
                "postalCode" => "60654",
                "locality" => "city",
                "administrativeArea" => "IL",
                "country" => "USA",
                "email" => "test@example.com",
            ],
            "lineItems" => [],
            "amountDetails" => [
                "totalAmount" => 111.0,
                "currency" => "USD",
            ],
        ],
        "paymentInformation" => [
            "card" => [
                "expirationYear" => 2025,
                "number" => "4111111111111111",
                "securityCode" => 123,
                "expirationMonth" => 12,
            ],
        ],
    ];

    private array $customerCybersourceResponse = [
        "clientReferenceInformation" => [
            "code" => 12345,
        ],
        "buyerInformation" =>
        [
            "merchantCustomerID" => '1234',
            "email" => 'abc@gmail.com',
        ],
    ];

    private array $instrumentIdentifierResponseData = [
        "card" => [
            'number' => '4111111111111111',
        ],
    ];

    private array $paymentInstrumentResponseData = [
        "card" => [
            "expirationYear" => 2025,
            "expirationMonth" => 12,
            "type" => "001",
        ],
        "billTo" => [
            "firstName" => "firstName",
            "lastName" => "lastName",
            "address1" => "street",
            "postalCode" => "60654",
            "locality" => "city",
            "administrativeArea" => "IL",
            "country" => "USA",
            "email" => "test@example.com",
        ],
        "instrumentIdentifier" => [
            "id" => "1234",
        ],
    ];

    private array $paymentResponseWithExistingCardData = [
        "clientReferenceInformation" => [
            "code" => 12345,
        ],
        "orderInformation" => [
            "billTo" => [
                "firstName" => "firstName",
                "lastName" => "lastName",
                "address1" => "street",
                "postalCode" => "60654",
                "locality" => "city",
                "administrativeArea" => "IL",
                "country" => "USA",
                "email" => "test@example.com",
            ],
            "lineItems" => [],
            "amountDetails" => [
                "totalAmount" => 111.0,
                "currency" => "USD",
            ],
        ],
        'paymentInformation' => [
            'paymentInstrument' => [
                'id' => 1222,
            ],
        ],
    ];

    private $captureResponseData = [
        "clientReferenceInformation" => [
            "code" => 12345,
        ],
        "orderInformation" => [
            "amountDetails" => [
                "totalAmount" => 111.0,
                "currency" => "USD",
            ],
            "lineItems" => [],
        ],
    ];

    private $reversalResponseData = [
        "clientReferenceInformation" => [
            "code" => 12345,
        ],
        "reversalInformation" => [
            "amountDetails" => [
                "totalAmount" => 111.0,
            ],
        ],
        "reason" => "An error occurred while processing the payment request.
                The attempted authorization has been reversed.",
    ];

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testMakePaymentRequestWithExistingCard()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            $this->isSavedCardChecked
        );
        $result = $paymentAuth->makePaymentRequest();
        $this->assertEquals($this->paymentResponseWithExistingCardData, $result);
    }

    public function testMakeInstrumentIdentifierRequest()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            false
        );
        $result = $paymentAuth->makeInstrumentIdentifierRequest();
        $this->assertEquals($this->instrumentIdentifierResponseData, $result);
    }

    public function testMakePaymentRequestWithNewCard()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            false
        );
        $result = $paymentAuth->makePaymentRequest();
        $this->assertEquals($this->paymentResponseData, $result);
    }

    public function testGetCybersourceCustomerRequestPayload()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            false
        );
        $result = $paymentAuth->getCybersourceCustomerRequestPayload('1234', 'abc@gmail.com');
        $this->assertEquals($this->customerCybersourceResponse, $result);
    }

    public function testGetPaymentInstrumentRequestPayload()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            false
        );
        $result = $paymentAuth->getPaymentInstrumentRequestPayload('1234');
        $this->assertEquals($this->paymentInstrumentResponseData, $result);
    }

    public function testMakeCapturePaymentRequest()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            $this->isSavedCardChecked
        );
        $result = $paymentAuth->makeCapturePaymentRequest();
        $this->assertEquals($this->captureResponseData, $result);
    }

    public function testMakeAuthReversalPaymentRequest()
    {
        $paymentAuth = new PaymentAuth(
            $this->getOrderMockObject(),
            $this->getCustomerMockObject(),
            $this->cardInformation,
            $this->isSavedCardChecked
        );
        $result = $paymentAuth->makeAuthReversalPaymentRequest();
        $this->assertEquals($this->reversalResponseData, $result);
    }

    private function getOrderMockObject()
    {
        $order = \Mockery::mock(OrderEntity::class, function (MockInterface $mock) {
            $mock->shouldReceive('getOrderNumber')->andReturn('12345');
            $mock->shouldReceive('getAmountTotal')->andReturn('111');
            $mock->shouldReceive('getCurrency')->andReturnUsing(function () {
                $currencyEntity = \Mockery::mock('Shopware\Core\System\Currency\CurrencyEntity');
                $currencyEntity->shouldReceive('getIsoCode')->andReturn('USD');
                return $currencyEntity;
            });
        });

        $mockLineItemsEntity = \Mockery::mock(OrderLineItemCollection::class);
        $mockLineItemsEntity->shouldReceive("getElements")->andReturn([]);
        $order->shouldReceive("getLineItems")->andReturn($mockLineItemsEntity);

        return $order;
    }

    private function getCustomerMockObject()
    {
        $customer = \Mockery::mock(CustomerEntity::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEmail')->andReturn('test@example.com');
            $mock->shouldReceive('getFirstName')->andReturn('firstName');
            $mock->shouldReceive('getLastName')->andReturn('lastName');
            $mock->shouldReceive('getActiveBillingAddress')->andReturnUsing(function () {
                $customerAddressEntity = \Mockery::mock(
                    'Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity'
                );
                $customerAddressEntity->shouldReceive('getStreet')->andReturn('street');
                $customerAddressEntity->shouldReceive('getCity')->andReturn('city');
                $customerAddressEntity->shouldReceive('getCountryState')->andReturnUsing(function () {
                    $countryStateEntity = \Mockery::mock(
                        'Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity'
                    );
                    $countryStateEntity->shouldReceive('getShortCode')->andReturn('US-IL');
                    return $countryStateEntity;
                });
                $customerAddressEntity->shouldReceive('getZipcode')->andReturn('60654');
                $customerAddressEntity->shouldReceive('getCountry')->andReturnUsing(function () {
                    $countryEntity = \Mockery::mock('Shopware\Core\System\Country\CountryEntity');
                    $countryEntity->shouldReceive('getIso')->andReturn('USA');
                    return $countryEntity;
                });
                return $customerAddressEntity;
            });
        });

        return $customer;
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
