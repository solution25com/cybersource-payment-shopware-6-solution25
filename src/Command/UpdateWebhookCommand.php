<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use CyberSource\Shopware6\Helper\UrlHelper;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateWebhookCommand extends Command
{
    protected static $defaultName = 'cybersource:update-webhook';

    private CyberSourceApiClient $apiClient;
    private SystemConfigService $systemConfigService;
    private UrlHelper $urlHelper;

    public function __construct(
        CyberSourceApiClient $apiClient,
        SystemConfigService $systemConfigService,
        UrlHelper $urlHelper
    ) {
        parent::__construct();
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
        $this->urlHelper = $urlHelper;
    }

    protected function configure(): void
    {
        $this->setName('cybersource:update-webhook');
        $this->setDescription('Updates an existing CyberSource webhook.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');

        if (!$webhookId) {
            $output->writeln('Error: No webhook ID found in configuration. Please create a webhook first.');
            return Command::FAILURE;
        }

        $shopBaseUrl = $this->urlHelper->getShopwareBaseUrl();

        $webhookUrl = $shopBaseUrl . '/cybersource/webhook?XDEBUG_TRIGGER=PHPSTORM';
        $healthCheckUrl = $shopBaseUrl . '/cybersource/webhook/health?XDEBUG_TRIGGER=PHPSTORM';

        $payload = [
            'name' => 'ShopwarePaymentWebhookUpdated',
            'description' => 'Updated webhook for Shopware payment notifications',
            'organizationId' => $this->apiClient->getConfigurationService()->getOrganizationID(),
            'productId' => 'cardProcessing',
            'eventTypes' => [
                "payments.payments.accept",
                "payments.payments.review",
                "payments.payments.reject",
                "payments.payments.partial.approval",
                "payments.reversals.accept",
                "payments.reversals.reject",
                "payments.captures.accept",
                "payments.captures.review",
                "payments.captures.reject",
                "payments.refunds.accept",
                "payments.refunds.reject",
                "payments.refunds.partial.approval",
                "payments.credits.accept",
                "payments.credits.review",
                "payments.credits.reject",
                "payments.credits.partial.approval",
                "payments.voids.accept",
                "payments.voids.reject",
            ],
            'webhookUrl' => $webhookUrl,
            'healthCheckUrl' => $healthCheckUrl,
            'notificationScope' => 'SELF',
            'securityPolicy' => [
                'securityType' => 'none',
            ],
            'status' => 'ACTIVE'
        ];

        $output->writeln('Updating CyberSource webhook with ID: ' . $webhookId);

        try {
            $response = $this->apiClient->updateWebhook($webhookId, $payload);
            $output->writeln('Webhook update response:');
            $output->writeln('Status Code: ' . $response['statusCode']);
            $output->writeln('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));

            if ($response['statusCode'] === 200 && isset($response['body']['securityPolicy']['sharedSecret'])) {
                $sharedSecret = $response['body']['securityPolicy']['sharedSecret'];
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookSharedSecret', $sharedSecret);
                $output->writeln('Updated webhook shared secret in configuration.');
            }
        } catch (\Exception $e) {
            $output->writeln('Error updating webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}