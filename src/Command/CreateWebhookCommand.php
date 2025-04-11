<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use CyberSource\Shopware6\Helper\UrlHelper;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateWebhookCommand extends Command
{
    protected static $defaultName = 'cybersource:create-webhook';

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
        $this->setName('cybersource:create-webhook');
        $this->setDescription('Creates a CyberSource webhook for payment notifications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopBaseUrl = $this->urlHelper->getShopwareBaseUrl();
        $webhookUrl = $shopBaseUrl . '/cybersource/webhook';
        $healthCheckUrl = $shopBaseUrl . '/cybersource/webhook/health';

        $output->writeln('Webhook URL: ' . $webhookUrl);
        $output->writeln('Health Check URL: ' . $healthCheckUrl);

        $payload = [
            'name' => 'ShopwarePaymentWebhook',
            'description' => 'Webhook for Shopware payment notifications',
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
                'securityType' => 'sharedSecret',
            ],
            'status' => 'ACTIVE'
        ];

        $output->writeln('Creating CyberSource webhook...');

        try {
            $response = $this->apiClient->createWebhook($payload);
            $output->writeln('Webhook creation response:');
            $output->writeln('Status Code: ' . $response['statusCode']);
            $output->writeln('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));

            if ($response['statusCode'] === 201) {
                if (isset($response['body']['webhookId'])) {
                    $webhookId = $response['body']['webhookId'];
                    $this->systemConfigService->set('CyberSourceShopware6.config.webhookId', $webhookId);
                    $output->writeln('Stored webhook ID in configuration: ' . $webhookId);
                }

                if (isset($response['body']['securityPolicy']['sharedSecret'])) {
                    $sharedSecret = $response['body']['securityPolicy']['sharedSecret'];
                    $this->systemConfigService->set('CyberSourceShopware6.config.webhookSharedSecret', $sharedSecret);
                    $output->writeln('Stored webhook shared secret in configuration.');
                }
            }
        } catch (\Exception $e) {
            $output->writeln('Error creating webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}