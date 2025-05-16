<?php

declare(strict_types=1);

namespace CyberSource\Shopware6;

use CyberSource\Shopware6\Service\CustomFieldService;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Context;
use CyberSource\Shopware6\PaymentMethods;
use CyberSource\Shopware6\Contracts\Identity;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CyberSourceShopware6 extends Plugin
{
    private ?CustomFieldService $customFieldService = null;

    public function install(InstallContext $installContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->addPaymentMethod(new $paymentMethod(), $installContext->getContext());
        }
        $this->getCustomFieldsInstaller()->createCustomFields($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $uninstallContext->getContext(), new $paymentMethod());
        }

        if ($uninstallContext->keepUserData()) {
            return;
        }
        $this->getCustomFieldsInstaller()->remove($uninstallContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(true, $activateContext->getContext(), new $paymentMethod());
        }
        $this->getCustomFieldsInstaller()->createCustomFields($activateContext->getContext());
        $this->runWebhookCommand('cybersource:create-key');
        $this->runWebhookCommand('cybersource:create-webhook');
        $this->runWebhookCommand('cybersource:update-status-webhook --active=true');
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), new $paymentMethod());
        }
        $this->runWebhookCommand('cybersource:delete-webhook');
        parent::deactivate($deactivateContext);
    }


    private function runWebhookCommand(string $command): void
    {
        try {
            $process = new Process(['php', 'bin/console', $command], $this->getProjectDir());
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        }
        catch (ProcessFailedException $exception) {
            // Handle the exception as needed
        }
    }

    /**
     * Get the project root directory.
     *
     * @return string
     */
    private function getProjectDir(): string
    {
        return $this->container->getParameter('kernel.project_dir');
    }

    private function addPaymentMethod(Identity $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        $pluginIdProvider = $this->getDependency(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        if ($paymentMethodId) {
            $this->setPluginId($paymentMethodId, $pluginId, $context);
            return;
        }

        $paymentData = [
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'description' => $paymentMethod->getDescription(),
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
            'translations' => [
                '2fbb5fe2e29a4d70aa5854ce7ce3e20b' => [
                    'name' => $paymentMethod->getName(),
                    'description' => $paymentMethod->getDescription(),
                ],
            ],
        ];

        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPluginId(string $paymentMethodId, string $pluginId, Context $context): void
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodData = [
            'id' => $paymentMethodId,
            'pluginId' => $pluginId,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function getPaymentMethodId(string $paymentMethodHandler): ?string
    {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter(
            'handlerIdentifier',
            $paymentMethodHandler
        ));

        $paymentIds = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }

    private function setPaymentMethodIsActive(
        bool $active,
        Context $context,
        Identity $paymentMethod
    ): void {
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler());

        if (!$paymentMethodId) {
            return;
        }

        $paymentMethodData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    public function getDependency($name): mixed
    {
        return $this->container->get($name);
    }
    private function getCustomFieldsInstaller(): CustomFieldService
    {
        if ($this->container->has(CustomFieldService::class)) {
            $installer = $this->container->get(CustomFieldService::class);
            if ($installer instanceof CustomFieldService) {
                return $installer;
            }
        }

        return new CustomFieldService(
            $this->container->get('custom_field_set.repository'),
            $this->container->get('custom_field_set_relation.repository')
        );
    }
}