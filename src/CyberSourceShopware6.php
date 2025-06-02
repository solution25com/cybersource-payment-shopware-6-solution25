<?php

declare(strict_types=1);

namespace CyberSource\Shopware6;

use CyberSource\Shopware6\Service\CustomFieldService;
use CyberSource\Shopware6\Service\WebhookService;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;
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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CyberSourceShopware6 extends Plugin
{
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

        if ($this->container === null) {
            throw new \RuntimeException('Container is not initialized.');
        }
        /** @var WebhookService $webhookService */
        $webhookService = $this->container->get(WebhookService::class);
        $io = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput());

        $webhookService->createKey($io);
        $webhookService->createWebhook(
            'ShopwarePaymentWebhook' . time(),
            $webhookService->getWebhookUrl($activateContext->getContext()),
            $webhookService->getHealthCheckUrl($activateContext->getContext()),
            $io
        );
        $webhookService->updateWebhookStatus(true, $io);

        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        foreach (PaymentMethods\PaymentMethods::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodIsActive(false, $deactivateContext->getContext(), new $paymentMethod());
        }
        if ($this->container === null) {
            throw new \RuntimeException('Container is not initialized.');
        }
        /** @var WebhookService $webhookService */
        $webhookService = $this->container->get(WebhookService::class);
        $io = new SymfonyStyle(new ArrayInput([]), new ConsoleOutput());
        $webhookService->deleteWebhook($io);

        parent::deactivate($deactivateContext);
    }

    private function addPaymentMethod(Identity $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler(), $context);

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

        /** @var EntityRepository<PaymentMethodCollection> $paymentRepository */
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPluginId(string $paymentMethodId, string $pluginId, Context $context): void
    {
        /** @var EntityRepository<PaymentMethodCollection> $paymentRepository */
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodData = [
            'id' => $paymentMethodId,
            'pluginId' => $pluginId,
        ];
        $paymentRepository->update([$paymentMethodData], $context);
    }

    private function getPaymentMethodId(string $paymentMethodHandler, Context $context): ?string
    {
        /** @var EntityRepository<PaymentMethodCollection> $paymentRepository */
        $paymentRepository = $this->getDependency('payment_method.repository');
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethodHandler));
        $criteria->setLimit(1);

        $paymentMethod = $paymentRepository->search($criteria, $context)->first();

        return $paymentMethod instanceof PaymentMethodEntity ? $paymentMethod->getId() : null;
    }

    private function setPaymentMethodIsActive(bool $active, Context $context, Identity $paymentMethod): void
    {
        /** @var EntityRepository<PaymentMethodCollection> $paymentRepository */
        $paymentRepository = $this->getDependency('payment_method.repository');
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod->getPaymentHandler(), $context);

        if (!$paymentMethodId) {
            return;
        }

        $paymentMethodData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethodData], $context);
    }

    public function getDependency(string $name): mixed
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is not initialized.');
        }
        return $this->container->get($name);
    }

    private function getCustomFieldsInstaller(): CustomFieldService
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is not initialized.');
        }

        if ($this->container->has(CustomFieldService::class)) {
            $installer = $this->container->get(CustomFieldService::class);
            if ($installer instanceof CustomFieldService) {
                return $installer;
            }
        }

        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        /** @var EntityRepository<CustomFieldSetRelationCollection> $customFieldSetRelationRepository */
        $customFieldSetRelationRepository = $this->container->get('custom_field_set_relation.repository');

        return new CustomFieldService($customFieldSetRepository, $customFieldSetRelationRepository);
    }
}