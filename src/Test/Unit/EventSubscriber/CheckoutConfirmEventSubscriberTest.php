<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use CyberSource\Shopware6\Storefront\Struct\CheckoutTemplateCustomData;
use CyberSource\Shopware6\EventSubscriber\CheckoutConfirmEventSubscriber;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;

class CheckoutConfirmEventSubscriberTest extends TestCase
{
    private EntityRepository $orderTransactionRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderTransactionRepo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testGetSubscribedEventsMethod(): void
    {
        $expectedResult = [
            "Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent" => "addPaymentMethodSpecificFormFields", //phpcs:ignore
            "Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent" => "addPaymentMethodSpecificFormFields", //phpcs:ignore
        ];
        $eventSubscriberClass = new CheckoutConfirmEventSubscriber($this->orderTransactionRepo);
        $actualResult = $eventSubscriberClass->getSubscribedEvents();

        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testAddPaymentMethodSpecificFormFields(): void
    {
        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockEventClass = \Mockery::mock(CheckoutConfirmPageLoadedEvent::class);
        $mockContextClass = \Mockery::mock(Context::class);
        $mockCheckoutConfirmPage = $this->createMock(CheckoutConfirmPage::class);
        $salesChannelContext = \Mockery::mock(SalesChannelContext::class);
        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn([]);

        $mockEventClass->shouldReceive('getPage')
            ->once()
            ->andReturn($mockCheckoutConfirmPage);

        $mockEventClass->shouldReceive('getContext')
            ->andReturn($mockContextClass);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->once()
            ->andReturn($mockEntitySearch);

        $mockEventClass
            ->shouldReceive('getSalesChannelContext')
            ->andReturn($salesChannelContext);

        $mockPaymentMethodEntity = $this->createMock(PaymentMethodEntity::class);
        $mockPaymentMethodEntity->expects($this->once())
            ->method('getHandlerIdentifier')
            ->willReturn("CyberSource\Shopware6\Gateways\CreditCard");

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getGuest' => 0,
        ]);

        $salesChannelContext
            ->shouldReceive('getPaymentMethod')
            ->once()
            ->andReturn($mockPaymentMethodEntity);

        $salesChannelContext->shouldReceive('getCustomer')
            ->andReturn($mockCustomerEntity);

        $mockCheckoutConfirmPage->expects($this->once())
            ->method('addExtension')
            ->with(
                $this->equalTo('cybersource_shopware6'),
                $this->isInstanceOf(CheckoutTemplateCustomData::class)
            );

        $eventSubscriberClass = new CheckoutConfirmEventSubscriber($mockEntityRepository);
        $eventSubscriberClass->addPaymentMethodSpecificFormFields($mockEventClass);
    }

    public function testAddPaymentMethodSpecificFormFieldsWithUndefinedHandler(): void
    {
        $mockEntityRepository = \Mockery::mock(EntityRepository::class);

        $mockEventClass = \Mockery::mock(CheckoutConfirmPageLoadedEvent::class);
        $mockContextClass = \Mockery::mock(Context::class);
        $mockCheckoutConfirmPage = $this->createMock(CheckoutConfirmPage::class);
        $salesChannelContext = \Mockery::mock(SalesChannelContext::class);
        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom = \Mockery::mock(CustomerEntity::class);
        $mockCustomerCustom
            ->shouldReceive('getCustomFields')
            ->andReturn([]);

        $mockEventClass->shouldReceive('getPage')
            ->once()
            ->andReturn($mockCheckoutConfirmPage);

        $mockEventClass->shouldReceive('getContext')
            ->andReturn($mockContextClass);

        $mockEntitySearch = \Mockery::mock(EntitySearchResult::class);
        $mockEntitySearch
            ->shouldReceive('first')
            ->andReturn($mockCustomerCustom);

        $mockEntityRepository->shouldReceive('search')
            ->andReturn($mockEntitySearch);

        $mockEventClass
            ->shouldReceive('getSalesChannelContext')
            ->andReturn($salesChannelContext);

        $mockPaymentMethodEntity = $this->createMock(PaymentMethodEntity::class);
        $mockPaymentMethodEntity->expects($this->once())
            ->method('getHandlerIdentifier')
            ->willReturn("");

        $mockCustomerEntity = \Mockery::mock(CustomerEntity::class);
        $mockCustomerEntity->shouldReceive([
            'getId' => '1234',
            'getGuest' => 0,
        ]);

        $salesChannelContext
            ->shouldReceive('getPaymentMethod')
            ->once()
            ->andReturn($mockPaymentMethodEntity);

        $salesChannelContext->shouldReceive('getCustomer')
            ->andReturn($mockCustomerEntity);

        $mockCheckoutConfirmPage
            ->method('addExtension')
            ->with(
                $this->equalTo('cybersource_shopware6'),
                $this->isInstanceOf(CheckoutTemplateCustomData::class)
            );

        $eventSubscriberClass = new CheckoutConfirmEventSubscriber($mockEntityRepository);
        $eventSubscriberClass->addPaymentMethodSpecificFormFields($mockEventClass);
    }
}
