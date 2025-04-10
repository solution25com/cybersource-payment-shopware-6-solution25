<?php

namespace CyberSource\Shopware6\Test\Unit\Gateways;

use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session;
use CyberSource\Shopware6\Gateways\CreditCard;
use CyberSource\Shopware6\Library\CyberSource;
use CyberSource\Shopware6\Exceptions\APIException;
use Symfony\Component\HttpFoundation\RequestStack;
use CyberSource\Shopware6\Validation\CardValidator;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuth;
use CyberSource\Shopware6\Exceptions\InvalidRequestException;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuthFactory;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class CreditCardTest extends TestCase
{
    private OrderTransactionStateHandler $orderTransactionStateHandler;

    private EntityRepository $orderTransactionRepo;

    private $requestDataBag;
    protected function setUp(): void
    {
        parent::setUp();

        $this->orderTransactionStateHandler = $this->getMockBuilder(OrderTransactionStateHandler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->orderTransactionStateHandler->expects($this->any())
                ->method('paid');

        $this->orderTransactionRepo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();

        $faker = \Faker\Factory::create();
        $this->requestDataBag = \Mockery::mock(RequestDataBag::class);
        $this->requestDataBag->shouldReceive('get')
            ->with('cybersource_shopware6_expiry_date')
            ->andReturn("12/" . $faker->year('+9 years'));
        $this->requestDataBag->shouldReceive('get')
            ->with('cybersource_shopware6_card_no')
            ->andReturn($faker->creditCardNumber);
        $this->requestDataBag->shouldReceive('get')
            ->with('cybersource_shopware6_security_code')
            ->andReturn($faker->randomNumber(3));
        $this->requestDataBag->shouldReceive('get')
            ->with('cybersource_shopware6_save_card')
            ->andReturn(0);
        $this->requestDataBag->shouldReceive('get')
            ->with('cybersource_shopware6_saved_card')
            ->andReturn('123344');
    }

    public function testPayMethodWithAuthOnlyTransactionType()
    {
        $customer = $this->createMock(CustomerEntity::class);
        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $salesChannelContext->method('getCustomer')->willReturn($customer);

        $mockCardValidator->expects($this->once())
            ->method('validate')
            ->willReturnSelf();

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $responseFromAuthPayment = [
            'status' => 'AUTHORIZED'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authorizePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $this->orderTransactionRepo,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $this->requestDataBag, $salesChannelContext);
        $this->assertTrue(true);
    }

    public function testPayMethodWithAuthCaptureTransactionType()
    {
        $customer = $this->createMock(CustomerEntity::class);
        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $salesChannelContext->method('getCustomer')->willReturn($customer);

        $mockCardValidator->expects($this->once())
            ->method('validate')
            ->willReturnSelf();

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth_capture');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $responseFromAuthPayment = [
            'status' => 'PENDING'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authAndCapturePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $this->orderTransactionRepo,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $this->requestDataBag, $salesChannelContext);
        $this->assertTrue(true);
    }

    public function testCyberSourceException()
    {
        $customer = $this->createMock(CustomerEntity::class);
        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $salesChannelContext->method('getCustomer')->willReturn($customer);

        $mockCardValidator->expects($this->once())
            ->method('validate')
            ->willReturnSelf();

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth_capture');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $responseFromAuthPayment = [
            'status' => 'INVALID_REQUEST',
            'errorInformation' => [
                'reason' => 'MISSING_FIELD',
                'message' => 'Field is missing.',
            ],
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authAndCapturePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $this->orderTransactionRepo,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Field is missing.');

        $paymentHandler->pay($transaction, $this->requestDataBag, $salesChannelContext);
    }

    public function testDefaultException()
    {
        $customer = $this->createMock(CustomerEntity::class);
        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $salesChannelContext->method('getCustomer')->willReturn($customer);

        $mockCardValidator->expects($this->once())
            ->method('validate')
            ->willReturnSelf();

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willThrowException(new \Exception('An error occurred while processing the payment request.'));

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $this->orderTransactionRepo,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $this->expectException(APIException::class);
        $this->expectExceptionMessage('An error occurred while processing the payment request.');

        $paymentHandler->pay($transaction, $this->requestDataBag, $salesChannelContext);
    }

    public function testPayMethodWithExistingCard()
    {
        $customerCustomerFields = [
           "cybersource_customer_id" => "1478E66D7BCAFA",
           "cybersource_card_details" => [
                [
                    "1478CEDFB830" => [
                        "last4Digits" => 1111,
                        "instrumentIdentifierId" => "70362300",
                    ],
                ],
                [
                    "1478EC0A6C6F23F" => [
                        "last4Digits" => 436,
                        "instrumentIdentifierId" => "7039989999",
                    ],
                ],
            ],
        ];
        $faker = \Faker\Factory::create();
        $databgRequest = \Mockery::mock(RequestDataBag::class);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_expiry_date')
            ->andReturn("12/" . $faker->year('+9 years'));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_card_no')
            ->andReturn('');
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_security_code')
            ->andReturn($faker->randomNumber(3));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_save_card')
            ->andReturn(0);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_saved_card')
            ->andReturn('1478EC0A6C6F23F');

        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = $this->createMock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getEmail' => 'abc@gmail.com',
            'getGuest' => 0,
        ]);

        $salesChannelContext->method('getCustomer')->willReturn($mockCustomerEntity);

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn($customerCustomerFields);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearch);

        $responseFromAuthPayment = [
            'status' => 'AUTHORIZED'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authorizePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $mockEntityRepository,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $databgRequest, $salesChannelContext);
        $this->assertTrue(true);
    }

    public function testPayMethodWithSaveNewCard()
    {
        $customerCustomerFields = [
           "cybersource_customer_id" => "1478E66D7BCAFA",
           "cybersource_card_details" => [
                [
                    "1478CEDFB830" => [
                        "last4Digits" => 1111,
                        "instrumentIdentifierId" => "70362300",
                    ],
                ],
                [
                    "1478EC0A6C6F23F" => [
                        "last4Digits" => 436,
                        "instrumentIdentifierId" => "7039989999",
                    ],
                ],
            ],
        ];
        $faker = \Faker\Factory::create();
        $databgRequest = \Mockery::mock(RequestDataBag::class);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_expiry_date')
            ->andReturn("12/" . $faker->year('+9 years'));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_card_no')
            ->andReturn($faker->creditCardNumber);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_security_code')
            ->andReturn($faker->randomNumber(3));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_save_card')
            ->andReturn(1);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_saved_card')
            ->andReturn('123344');

        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = \Mockery::mock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getEmail' => 'abc@gmail.com',
            'getGuest' => 0,
        ]);

        $salesChannelContext->method('getCustomer')->willReturn($mockCustomerEntity);

        $mockSessionInterface = \Mockery::mock(SessionInterface::class);
        $mockSessionInterface->shouldReceive([
            'getFlashBag' => '1234',
        ]);

        $mockRequestStack->shouldReceive('getSession')->andReturn($mockSessionInterface);

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn($customerCustomerFields);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearch);

        $mockEntityWrittenContainer = \Mockery::mock(EntityWrittenContainerEvent::class);
        $mockEntityRepository->shouldReceive('update')
            ->once()
            ->andReturn($mockEntityWrittenContainer);

        $responseFromAuthPayment = [
            'status' => 'AUTHORIZED'
        ];

        $responseFromInstrumentIdentifier = [
            'id' => '1234'
        ];

        $responseFromPaymentInstrument = [
            'id' => '1234'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authorizePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('generateInstrumentIdentifier')
            ->willReturn($responseFromInstrumentIdentifier);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('createPaymentInstrument')
            ->willReturn($responseFromPaymentInstrument);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $mockEntityRepository,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $databgRequest, $salesChannelContext);
        $this->assertTrue(true);
    }

    public function testPayMethodWithSaveNewCardPendingStatus()
    {
        $customerCustomerFields = [
           "cybersource_customer_id" => "1478E66D7BCAFA",
           "cybersource_card_details" => [
                [
                    "1478CEDFB830" => [
                       "last4Digits" => 1111,
                       "instrumentIdentifierId" => "70362300",
                    ],
                ],
                [
                    "1478EC0A6C6F23F" => [
                        "last4Digits" => 436,
                        "instrumentIdentifierId" => "7039989999",
                    ],
                ],
            ],
        ];
        $faker = \Faker\Factory::create();
        $databgRequest = \Mockery::mock(RequestDataBag::class);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_expiry_date')
            ->andReturn("12/" . $faker->year('+9 years'));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_card_no')
            ->andReturn($faker->creditCardNumber);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_security_code')
            ->andReturn($faker->randomNumber(3));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_save_card')
            ->andReturn(1);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_saved_card')
            ->andReturn('123344');

        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = \Mockery::mock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getEmail' => 'abc@gmail.com',
            'getGuest' => 0,
        ]);

        $salesChannelContext->method('getCustomer')->willReturn($mockCustomerEntity);

        $mockFlashBagInterface = \Mockery::mock(FlashBagInterface::class);
        $mockFlashBagInterface->shouldReceive([
            'add' => null,
        ]);

        $mockSessionInterface = \Mockery::mock(FlashBagAwareSessionInterface::class);
        $mockSessionInterface->shouldReceive([
            'getFlashBag' => $mockFlashBagInterface,
        ]);

        $mockRequestStack->shouldReceive('getSession')->andReturn($mockSessionInterface);

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn($customerCustomerFields);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearch);

        $mockEntityWrittenContainer = \Mockery::mock(EntityWrittenContainerEvent::class);
        $mockEntityRepository->shouldReceive('update')
            ->once()
            ->andReturn($mockEntityWrittenContainer);

        $responseFromAuthPayment = [
            'status' => 'PENDING'
        ];

        $responseFromInstrumentIdentifier = [
            'id' => '1234'
        ];

        $responseFromPaymentInstrument = [
            'id' => '1234'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authorizePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('generateInstrumentIdentifier')
            ->willReturn($responseFromInstrumentIdentifier);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('createPaymentInstrument')
            ->willReturn($responseFromPaymentInstrument);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $mockEntityRepository,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $databgRequest, $salesChannelContext);
        $this->assertTrue(true);
    }

    public function testPayMethodWithSaveNewCardExistingInstrument()
    {
        $customerCustomerFields = [
           "cybersource_customer_id" => "1478E66D7BCAFA",
           "cybersource_card_details" => [
                [
                    "1478CEDFB830" => [
                        "last4Digits" => 1111,
                        "instrumentIdentifierId" => "70362300",
                    ],
                ],
                [
                    "1478EC0A6C6F23F" => [
                        "last4Digits" => 436,
                        "instrumentIdentifierId" => "7039989999",
                    ],
                ],
            ],
        ];
        $faker = \Faker\Factory::create();
        $databgRequest = \Mockery::mock(RequestDataBag::class);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_expiry_date')
            ->andReturn("12/" . $faker->year('+9 years'));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_card_no')
            ->andReturn($faker->creditCardNumber);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_security_code')
            ->andReturn($faker->randomNumber(3));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_save_card')
            ->andReturn(1);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_saved_card')
            ->andReturn('123344');

        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = \Mockery::mock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getEmail' => 'abc@gmail.com',
            'getGuest' => 0,
        ]);

        $salesChannelContext->method('getCustomer')->willReturn($mockCustomerEntity);

        $mockFlashBagInterface = \Mockery::mock(FlashBagInterface::class);
        $mockFlashBagInterface->shouldReceive([
            'add' => null,
        ]);

        $mockSessionInterface = \Mockery::mock(FlashBagAwareSessionInterface::class);
        $mockSessionInterface->shouldReceive([
            'getFlashBag' => $mockFlashBagInterface,
        ]);

        $mockRequestStack->shouldReceive('getSession')->andReturn($mockSessionInterface);

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn($customerCustomerFields);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearch);

        $mockEntityWrittenContainer = \Mockery::mock(EntityWrittenContainerEvent::class);
        $mockEntityRepository->shouldReceive('update')
            ->andReturn($mockEntityWrittenContainer);

        $responseFromAuthPayment = [
            'status' => 'AUTHORIZED'
        ];

        $responseFromInstrumentIdentifier = [
            'id' => '70362300'
        ];

        $responseFromPaymentInstrument = [
            'id' => '70362300'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authorizePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('generateInstrumentIdentifier')
            ->willReturn($responseFromInstrumentIdentifier);

        $mockCyberSourceLibrary
            ->method('createPaymentInstrument')
            ->willReturn($responseFromPaymentInstrument);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $mockEntityRepository,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $databgRequest, $salesChannelContext);
        $this->assertTrue(true);
    }

    public function testPayMethodWithSaveNewCardNewCustomer()
    {
        $customerCustomerFields = [
           "cybersource_card_details" => [
                [
                    "1478CEDFB830" => [
                        "last4Digits" => 1111,
                        "instrumentIdentifierId" => "70362300",
                    ],
                ],
                [
                    "1478EC0A6C6F23F" => [
                        "last4Digits" => 436,
                        "instrumentIdentifierId" => "7039989999",
                    ],
                ],
            ],
        ];
        $faker = \Faker\Factory::create();
        $databgRequest = \Mockery::mock(RequestDataBag::class);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_expiry_date')
            ->andReturn("12/" . $faker->year('+9 years'));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_card_no')
            ->andReturn($faker->creditCardNumber);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_security_code')
            ->andReturn($faker->randomNumber(3));
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_save_card')
            ->andReturn(1);
        $databgRequest->shouldReceive('get')
            ->with('cybersource_shopware6_saved_card')
            ->andReturn('123344');

        $mockPaymentAuth = $this->createMock(PaymentAuth::class);
        $mockCardValidator = $this->createMock(CardValidator::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $transaction = $this->createMock(SyncPaymentTransactionStruct::class);
        $mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $mockConfigurationService = $this->createMock(ConfigurationService::class);
        $mockPaymentAuthFactory = $this->createMock(PaymentAuthFactory::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockRequestStack = \Mockery::mock(RequestStack::class);
        $mockTranslaterInterface = $this->createMock(TranslatorInterface::class);

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getEmail' => 'abc@gmail.com',
            'getGuest' => 0,
        ]);

        $salesChannelContext->method('getCustomer')->willReturn($mockCustomerEntity);

        $mockFlashBagInterface = \Mockery::mock(FlashBagInterface::class);
        $mockFlashBagInterface->shouldReceive([
            'add' => null,
        ]);

        $mockSessionInterface = \Mockery::mock(FlashBagAwareSessionInterface::class);
        $mockSessionInterface->shouldReceive([
            'getFlashBag' => $mockFlashBagInterface,
        ]);

        $mockRequestStack->shouldReceive('getSession')->andReturn($mockSessionInterface);

        $mockPaymentAuthFactory->expects($this->once())
            ->method('createPaymentAuth')
            ->willReturn($mockPaymentAuth);

        $mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $mockConfigurationService->expects($this->once())
            ->method('getTransactionType')
            ->willReturn('auth');

        $mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn($customerCustomerFields);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearch);

        $mockEntityWrittenContainer = \Mockery::mock(EntityWrittenContainerEvent::class);
        $mockEntityRepository->shouldReceive('update')
            ->andReturn($mockEntityWrittenContainer);

        $responseFromAuthPayment = [
            'status' => 'AUTHORIZED'
        ];

        $responseFromInstrumentIdentifier = [
            'id' => '1234'
        ];

        $responseFromPaymentInstrument = [
            'id' => '1234'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('authorizePaymentWithCreditCard')
            ->willReturn($responseFromAuthPayment);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('generateInstrumentIdentifier')
            ->willReturn($responseFromInstrumentIdentifier);

        $mockCyberSourceLibrary
            ->method('createPaymentInstrument')
            ->willReturn($responseFromPaymentInstrument);

        $paymentHandler = new CreditCard(
            $mockConfigurationService,
            $mockCardValidator,
            $mockPaymentAuthFactory,
            $mockCyberSourceFactory,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepo,
            $mockEntityRepository,
            $mockRequestStack,
            $mockTranslaterInterface
        );

        $paymentHandler->pay($transaction, $databgRequest, $salesChannelContext);
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
