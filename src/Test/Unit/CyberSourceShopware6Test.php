<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use CyberSource\Shopware6\CyberSourceShopware6;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;

class CyberSourceShopware6Test extends TestCase
{
    private CyberSourceShopware6 $plugin;
    private InstallContext $installContext;

    protected function setUp(): void
    {
        $this->plugin = \Mockery::mock(CyberSourceShopware6::class)->makePartial();

        $mockMigrationCollection = \Mockery::mock(MigrationCollection::class);
        $this->installContext = new InstallContext(
            $this->plugin,
            Context::createDefaultContext(),
            '6.5.0',
            '1.0',
            $mockMigrationCollection
        );
        $this->plugin = \Mockery::mock(CyberSourceShopware6::class)->makePartial();
    }

    public function testInstallAddsPaymentMethodsWithUpdateExistingPlugins(): void
    {
        $mockEntityWrittenContainerEvent = \Mockery::mock(EntityWrittenContainerEvent::class);

        $paymentMethodID = (string) rand();

        $mockIdSearchResult = $this->createMock(IdSearchResult::class);

        // Check Assertion for getTotal, getIds methods call for single time
        $mockIdSearchResult->expects($this->once())->method('getTotal')->willReturn(1);
        $mockIdSearchResult->expects($this->once())->method('getIds')->willReturn([$paymentMethodID]);

        $mockPaymentMethodEntity = \Mockery::mock(EntityRepository::class);
        $mockPaymentMethodEntity->shouldReceive([
            'searchIds' => $mockIdSearchResult,
            'getPluginIdByBaseClass' => PluginIdProvider::class,
            'update' => $mockEntityWrittenContainerEvent,
        ]);

        $this->plugin->shouldReceive('getDependency')->times(3)->andReturn($mockPaymentMethodEntity);
        $this->plugin->shouldReceive('install')->once()->passthru();

        $this->plugin->install($this->installContext);
    }

    public function testInstallAddsPaymentMethodsWithCreateNewMethod(): void
    {
        $mockEntityWrittenContainerEvent = \Mockery::mock(EntityWrittenContainerEvent::class);

        $paymentMethodID = (string) rand();

        $mockIdSearchResult = $this->createMock(IdSearchResult::class);

        // Check Assertion for getTotal, getIds methods call for single time
        $mockIdSearchResult->expects($this->once())->method('getTotal')->willReturn(0);
        $mockIdSearchResult->expects($this->never())->method('getIds')->willReturn([$paymentMethodID]);

        $mockPaymentMethodEntity = \Mockery::mock(EntityRepository::class);
        $mockPaymentMethodEntity->shouldReceive([
            'searchIds' => $mockIdSearchResult,
            'getPluginIdByBaseClass' => PluginIdProvider::class,
            'create' => $mockEntityWrittenContainerEvent,
        ]);

        $this->plugin->shouldReceive('getDependency')->times(3)->andReturn($mockPaymentMethodEntity);
        $this->plugin->shouldReceive('install')->once()->passthru();

        $this->plugin->install($this->installContext);
    }

    public function testActivatePaymentMethodAction(): void
    {
        $mockIdSearchResult = $this->createMock(IdSearchResult::class);

        // Check Assertion for getTotal, getIds methods call for single time
        $mockIdSearchResult->expects($this->once())->method('getTotal')->willReturn(0);
        $mockIdSearchResult->expects($this->never())->method('getIds')->willReturn([rand()]);

        $mockPaymentMethodEntity = \Mockery::mock(EntityRepository::class);
        $mockPaymentMethodEntity->shouldReceive([
            'searchIds' => $mockIdSearchResult,
        ]);

        $this->plugin->shouldReceive('getDependency')->times(2)->andReturn($mockPaymentMethodEntity);
        $this->plugin->shouldReceive('activate')->once()->passthru();

        $mockContext = \Mockery::mock(Context::class);
        $mockActivateContext = \Mockery::mock(ActivateContext::class);
        $mockActivateContext->shouldReceive('getContext')->once()->andReturn($mockContext);
        $this->plugin->activate($mockActivateContext);
    }

    public function testActivateExstingPaymentMethodAction(): void
    {
        $mockIdSearchResult = $this->createMock(IdSearchResult::class);
        $mockEntityWrittenContainerEvent = \Mockery::mock(EntityWrittenContainerEvent::class);

        // Check Assertion for getTotal, getIds methods call for single time
        $mockIdSearchResult->expects($this->once())->method('getTotal')->willReturn(1);
        $mockIdSearchResult->expects($this->once())->method('getIds')->willReturn([(string) rand()]);

        $mockPaymentMethodEntity = \Mockery::mock(EntityRepository::class);
        $mockPaymentMethodEntity->shouldReceive([
            'searchIds' => $mockIdSearchResult,
            'update' => $mockEntityWrittenContainerEvent,
        ]);

        $this->plugin->shouldReceive('getDependency')->times(2)->andReturn($mockPaymentMethodEntity);
        $this->plugin->shouldReceive('activate')->once()->passthru();

        $mockContext = \Mockery::mock(Context::class);
        $mockActivateContext = \Mockery::mock(ActivateContext::class);
        $mockActivateContext->shouldReceive('getContext')->once()->andReturn($mockContext);
        $this->plugin->activate($mockActivateContext);
    }

    public function testDeactivatePaymentMethodAction(): void
    {
        $mockIdSearchResult = $this->createMock(IdSearchResult::class);

        // Check Assertion for getTotal, getIds methods call for single time
        $mockIdSearchResult->expects($this->once())->method('getTotal')->willReturn(0);
        $mockIdSearchResult->expects($this->never())->method('getIds')->willReturn([rand()]);

        $mockPaymentMethodEntity = \Mockery::mock(EntityRepository::class);
        $mockPaymentMethodEntity->shouldReceive([
            'searchIds' => $mockIdSearchResult,
        ]);

        $this->plugin->shouldReceive('getDependency')->andReturn($mockPaymentMethodEntity);
        $this->plugin->shouldReceive('deactivate')->once()->passthru();

        $mockContext = \Mockery::mock(Context::class);
        $mockDeactivateContext = \Mockery::mock(DeactivateContext::class);
        $mockDeactivateContext->shouldReceive('getContext')->once()->andReturn($mockContext);
        $this->plugin->deactivate($mockDeactivateContext);
    }

    public function testUninstallPaymentMethodAction(): void
    {
        $mockIdSearchResult = $this->createMock(IdSearchResult::class);

        $mockIdSearchResult->expects($this->once())->method('getTotal')->willReturn(0);
        $mockIdSearchResult->expects($this->never())->method('getIds')->willReturn([rand()]);

        $mockPaymentMethodEntity = \Mockery::mock(EntityRepository::class);
        $mockPaymentMethodEntity->shouldReceive([
            'searchIds' => $mockIdSearchResult,
        ]);

        $this->plugin->shouldReceive('getDependency')->andReturn($mockPaymentMethodEntity);
        $this->plugin->shouldReceive('uninstall')->once()->passthru();

        $mockContext = \Mockery::mock(Context::class);
        $mockUninstallContext = \Mockery::mock(UninstallContext::class);
        $mockUninstallContext->shouldReceive('getContext')->once()->andReturn($mockContext);
        $mockUninstallContext->shouldReceive('keepUserData')->once()->andReturn(true);
        $this->plugin->uninstall($mockUninstallContext);
    }
}
