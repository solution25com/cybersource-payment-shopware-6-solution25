<?php

namespace CyberSource\Shopware6\Test\Unit\Controllers;

use Mockery;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Order\OrderEntity;
use CyberSource\Shopware6\Library\CyberSource;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use CyberSource\Shopware6\Exceptions\APIException;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;
use CyberSource\Shopware6\Controllers\CyberSourceController;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use CyberSource\Shopware6\Exceptions\OrderRefundPaymentStateException;
use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use CyberSource\Shopware6\Exceptions\BadRequestException;

class CyberSourceControllerTest extends TestCase
{
    private $faker;
    private $controllerInstance;
    private $orderId;
    private $cybersourceOrderTransactionId;
    private $mockEntityRepository;
    private $mockConfigurationService;
    private $mockCyberSourceFactory;
    private $mockOrderTransactionStateHandler;
    private $mockContext;
    private $mockRequest;
    private $translator;
    private $orderLineItemRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faker = \Faker\Factory::create();

        $this->orderId = $this->faker->bothify('??#####???####??######????????');
        $this->cybersourceOrderTransactionId = $this->faker->bothify('??#####???####??######????????');
        $this->mockEntityRepository = Mockery::mock(EntityRepository::class);
        $this->mockConfigurationService = $this->createMock(ConfigurationService::class);
        $this->mockCyberSourceFactory = $this->createMock(CyberSourceFactory::class);
        $this->mockOrderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->mockContext = $this->createMock(Context::class);
        $this->mockRequest = Mockery::mock(Request::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->orderLineItemRepository = Mockery::mock(EntityRepository::class);
    }

    public function testGetShopwareOrderTransactionDetails()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );
        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('USD');
        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('open');
        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);
        $orderTransactionEntity->customFields = [
            "cybersource_payment_details" => [
                "transaction_id" => "7091244786796741404953"
            ]
        ];

        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $response = $this->controllerInstance->getShopwareOrderTransactionDetails(
            $this->orderId,
            $this->mockContext
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function testGetShopwareOrderTransactionDetailsOrderTransactionNotFoundException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('open');
        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->customFields = [
            "cybersource_payment_detail" => [
                "transaction_id" => "7091244786796741404953"
            ]
        ];

        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND');
        $this->expectException(OrderTransactionNotFoundException::class);

        $this->controllerInstance->getShopwareOrderTransactionDetails(
            $this->orderId,
            $this->mockContext
        );
    }

    public function testGetShopwareOrderTransactionDetailsWithException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $orderTransactionEntity = null;

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND');
        $this->expectException(OrderTransactionNotFoundException::class);

        $this->controllerInstance->getShopwareOrderTransactionDetails(
            $this->orderId,
            $this->mockContext
        );
    }

    public function testCaptureAuthorizeGetOrderTransactionByOrderIdException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $orderTransactionEntity = null;

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.SHOPWARE_ORDER_TRANSACTION_NOT_FOUND');
        $this->expectException(OrderTransactionNotFoundException::class);

        $this->controllerInstance->captureAuthorize(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext
        );
    }

    public function testCaptureAuthorizeSuccess()
    {
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);

        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $mockLineItems = new OrderLineItemCollection([]);

        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('EUR');

        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('open');

        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);


        $this->mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $this->mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $orderTransactionStateHandler = $this->getMockBuilder(OrderTransactionStateHandler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderTransactionStateHandler->expects($this->any())
                ->method('paid');

        $this->mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $responseFromAuthPayment = [
            'status' => 'paid'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('capturePayment')
            ->willReturn($responseFromAuthPayment);

        $response = $this->controllerInstance->captureAuthorize(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext
        );

        $expectedResponse = new JsonResponse([
            'status' => 'paid'
        ]);

        $this->assertEquals($expectedResponse, $response);
    }

    public function testCaptureAuthorizeAPIException()
    {
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);

        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $mockLineItems = new OrderLineItemCollection([]);
        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('EUR');

        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('open');

        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);

        $this->mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $this->mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $this->mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('capturePayment')
            ->willThrowException(new \Exception('An error occurred while processing the payment request.'));

        $this->expectException(APIException::class);
        $this->expectExceptionMessage('An error occurred while processing the payment request.');

        $this->controllerInstance->captureAuthorize(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext
        );
    }

    public function testOrderRefundWithOrderTransactionNotFoundException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );
        $request = '{"newTotalAmount":0}';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $orderTransactionEntity = null;

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.CYBERSOURCE_ORDER_TRANSACTION_NOT_FOUND');
        $this->expectException(OrderTransactionNotFoundException::class);

        $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
    }

    public function testOrderRefundPaymentStateException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $request = '{"newTotalAmount":0}';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $mockLineItems = new OrderLineItemCollection([]);
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';

        $stateMachineStateEntity = new StateMachineStateEntity();
        $orderEntity = new OrderEntity();
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);
        $stateMachineStateEntity->setTechnicalName('open');
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);

        $this->expectException(OrderRefundPaymentStateException::class);
        $this->expectExceptionMessage('cybersource_shopware6.exception.REFUND_TRANSACTION_NOT_ALLOWED');

        $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
    }

    public function testOrderRefundAPIException()
    {
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);

        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $request = '{"newTotalAmount":0}';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $mockLineItems = new OrderLineItemCollection([]);

        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('EUR');

        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('paid');

        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);

        $this->mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $this->mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $orderTransactionStateHandler = $this->getMockBuilder(OrderTransactionStateHandler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderTransactionStateHandler->expects($this->any())
                ->method('paid');

        $this->mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $mockCyberSourceLibrary->expects($this->once())
            ->method('refundPayment')
            ->willThrowException(new \Exception('An error occurred while processing the payment request.'));

        $this->expectException(APIException::class);
        $this->expectExceptionMessage('An error occurred while processing the payment request.');

        $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
    }

    public function testOrderRefundSuccess()
    {
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);

        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );

        $request = '{"newTotalAmount":0}';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $calculatedTax = new CalculatedTax(1.90, 1.0, 19.00);
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $calculatedPrice1 = new CalculatedPrice(
            10.00,
            11.90,
            new CalculatedTaxCollection([$calculatedTax]),
            new TaxRuleCollection(),
            1
        );

        $orderLineItem1 = new OrderLineItemEntity();
        $orderLineItem1->setId('1');
        $orderLineItem1->setLabel('Product 1');
        $orderLineItem1->setPrice($calculatedPrice1);
        $orderLineItem1->setQuantity(1);
        $orderLineItem1->setPayload(['productNumber' => 123]);

        $calculatedPrice2 = new CalculatedPrice(
            20.00,
            21.90,
            new CalculatedTaxCollection([
                $calculatedTax
            ]),
            new TaxRuleCollection(),
            2
        );

        $orderLineItem2 = new OrderLineItemEntity();
        $orderLineItem2->setId('2');
        $orderLineItem2->setLabel('Product 2');
        $orderLineItem2->setPrice($calculatedPrice2);
        $orderLineItem2->setQuantity(1);

        $orderLineItems = [$orderLineItem1, $orderLineItem2];

        $mockLineItems = new OrderLineItemCollection($orderLineItems);
        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('EUR');

        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('paid');

        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);
        $orderTransactionEntity->customFields = [
            "cybersource_payment_details" => [
                "transaction_id" => "7091244786796741404953"
            ]
        ];

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);

        $this->mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $this->mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);

        $orderTransactionStateHandler = $this->getMockBuilder(OrderTransactionStateHandler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderTransactionStateHandler->expects($this->any())
                ->method('paid');

        $this->mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $responseFromAuthPayment = [
            'status' => 'paid'
        ];

        $mockCyberSourceLibrary->expects($this->once())
            ->method('refundPayment')
            ->willReturn($responseFromAuthPayment);

        $mocktransactionUpdateEntity = \Mockery::mock(EntityWrittenContainerEvent::class);
        $this->mockEntityRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($mocktransactionUpdateEntity);

        $response = $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
        $expectedResponse = new JsonResponse([
            'status' => 'paid'
        ]);
        $this->assertEquals($expectedResponse, $response);
    }
    public function testOrderRefundWithBadRequestException()
    {
        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);

        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );
        $request = '{"newTotalAmount":-1}';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $calculatedTax = new CalculatedTax(1.90, 1.0, 19.00);
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $calculatedPrice1 = new CalculatedPrice(
            10.00,
            11.90,
            new CalculatedTaxCollection([$calculatedTax]),
            new TaxRuleCollection(),
            1
        );

        $orderLineItem1 = new OrderLineItemEntity();
        $orderLineItem1->setId('1');
        $orderLineItem1->setLabel('Product 1');
        $orderLineItem1->setPrice($calculatedPrice1);
        $orderLineItem1->setQuantity(1);
        $orderLineItem1->setPayload(['productNumber' => 123]);

        $mockLineItems = new OrderLineItemCollection([$orderLineItem1]);
        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('EUR');

        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);

        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('refunded_partially');

        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);
        $orderTransactionEntity->customFields = [
            "cybersource_payment_details" => [
                "transaction_id" => "7091244786796741404953"
            ]
        ];

        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);
        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.CYBERSOURCE_REFUND_AMOUNT_INCORRECT');
        $this->expectException(BadRequestException::class);

        $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
    }
    public function testOrderRefundWithPartialRefundSuccess()
    {

        $mockEntitySearchResult = Mockery::mock(EntitySearchResult::class);
        $mockRequestSignatureContract = $this->createMock(RequestSignatureContract::class);
        $mockCyberSourceLibrary = $this->createMock(CyberSource::class);

        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );
        $request = '
        {
            "newTotalAmount":10,
            "lineItems":[
                {
                    "number": 1,
                    "productName": "Product 1",
                    "productCode": 123,
                    "unitPrice": 10,
                    "totalAmount": 11.9,
                    "quantity": 1,
                    "taxAmount": 1.9,
                    "productSku": 123
                }
            ]
        }';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $calculatedTax = new CalculatedTax(1.90, 1.0, 19.00);
        $calculatedPrice1 = new CalculatedPrice(
            10.00,
            11.90,
            new CalculatedTaxCollection([$calculatedTax]),
            new TaxRuleCollection(),
            1
        );
        $orderLineItem1 = new OrderLineItemEntity();
        $orderLineItem1->setId('1');
        $orderLineItem1->setLabel('Product 1');
        $orderLineItem1->setPrice($calculatedPrice1);
        $orderLineItem1->setQuantity(1);
        $orderLineItem1->setPayload(['productNumber' => 123]);

        $mockLineItems = new OrderLineItemCollection([$orderLineItem1]);
        $orderTransactionEntity = new OrderTransactionEntity();
        $orderTransactionEntity->id = '123rtutj3595cXogk42Slorfj3245';
        $currencyEntity = new CurrencyEntity();
        $currencyEntity->setShortName('EUR');

        $orderEntity = new OrderEntity();
        $orderEntity->setCurrency($currencyEntity);
        $orderEntity->setAmountTotal('100.00');
        $orderEntity->setLineItems($mockLineItems);
        $stateMachineStateEntity = new StateMachineStateEntity();
        $stateMachineStateEntity->setTechnicalName('paid');
        $orderTransactionEntity->setStateMachineState($stateMachineStateEntity);
        $orderTransactionEntity->setOrder($orderEntity);
        $orderTransactionEntity->customFields = [
            "cybersource_payment_details" => [
                "transaction_id" => "7091244786796741404953"
            ]
        ];
        $this->mockEntityRepository
            ->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearchResult);

        $mockEntitySearchResult
            ->shouldReceive('first')
            ->once()
            ->andReturn($orderTransactionEntity);

        $mockOrderEntity = $this->createMock(OrderEntity::class);
        $mockOrderEntity->method('getLineItems')->willReturn($mockLineItems);

        $mockOrderTransaction = $this->createMock(OrderTransactionEntity::class);
        $mockOrderTransaction->method('getOrder')->willReturn($mockOrderEntity);
        $this->mockConfigurationService->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn(EnvironmentUrl::TEST);

        $this->mockConfigurationService->expects($this->once())
            ->method('getSignatureContract')
            ->willReturn($mockRequestSignatureContract);
        $orderTransactionStateHandler = $this->getMockBuilder(OrderTransactionStateHandler::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderTransactionStateHandler->expects($this->any())
                ->method('paid');
        $this->mockCyberSourceFactory->expects($this->once())
            ->method('createCyberSource')
            ->willReturn($mockCyberSourceLibrary);

        $responseFromAuthPayment = [
            'status' => 'refunded_partially'
        ];
        $mockCyberSourceLibrary->expects($this->once())
            ->method('refundPayment')
            ->willReturn($responseFromAuthPayment);

        $this->mockEntityRepository
            ->shouldReceive('refundPartially');

        $mocktransactionUpdateEntity = \Mockery::mock(EntityWrittenContainerEvent::class);
            $this->mockEntityRepository
                ->shouldReceive('update')
                ->once()
                ->andReturn($mocktransactionUpdateEntity);
        $response = $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
        $expectedResponse = new JsonResponse($responseFromAuthPayment);
        $this->assertEquals($expectedResponse, $response);
    }

    public function testOrderRefundWithMissingRequestPayloadException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );
        $request = null;
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.CYBERSOURCE_REFUND_AMOUNT_INCORRECT');
        $this->expectException(BadRequestException::class);

        $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
    }
    public function testOrderRefundWithIncorrectRequestPayloadException()
    {
        $this->controllerInstance = new CyberSourceController(
            $this->mockEntityRepository,
            $this->mockConfigurationService,
            $this->mockCyberSourceFactory,
            $this->mockOrderTransactionStateHandler,
            $this->translator
        );
        $request = '{}';
        $this->mockRequest->shouldReceive('getContent')->once()->andReturn($request);

        $this->translator->expects($this->once())
            ->method('trans')
            ->willReturn('cybersource_shopware6.exception.CYBERSOURCE_REFUND_AMOUNT_INCORRECT');
        $this->expectException(BadRequestException::class);

        $this->controllerInstance->orderRefund(
            $this->orderId,
            $this->cybersourceOrderTransactionId,
            $this->mockContext,
            $this->mockRequest
        );
    }
}
