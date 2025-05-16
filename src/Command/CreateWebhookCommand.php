<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use CyberSource\Shopware6\Service\UrlService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateWebhookCommand extends Command
{
    protected static $defaultName = 'cybersource:create-webhook';

    private CyberSourceApiClient $apiClient;
    private SystemConfigService $systemConfigService;
    private UrlService $urlService;

    public function __construct(
        CyberSourceApiClient $apiClient,
        SystemConfigService $systemConfigService,
        UrlService $urlService
    ) {
        parent::__construct();
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
        $this->urlService = $urlService;
    }

    protected function configure(): void
    {
        $this->setName('cybersource:create-webhook');
        $this->setDescription('Creates a CyberSource webhook for payment notifications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shopBaseUrl = $this->urlService->getShopwareBaseUrl();
        $webhookUrl = $shopBaseUrl . '/cybersource/webhook';
        $healthCheckUrl = $shopBaseUrl . '/cybersource/webhook/health';

        $output->writeln('Webhook URL: ' . $webhookUrl);
        $output->writeln('Health Check URL: ' . $healthCheckUrl);

        $sharedSecretKeyId = $this->systemConfigService->get('CyberSourceShopware6.config.sharedSecretKeyId');
        if (!$sharedSecretKeyId) {
            $output->writeln('Error: Shared secret key not found. Run "cybersource:create-key" first.');
            return Command::FAILURE;
        }

        $payload = [
            'name' => 'ShopwarePaymentWebhook',
            'description' => 'Webhook for Shopware payment notifications',
            'organizationId' => $this->apiClient->getConfigurationService()->getOrganizationID(),
            'products' => [
                [
                    'productId' => 'cardProcessing',
                    'eventTypes' => [
                        'payments.payments.accept',
                        'payments.payments.review',
                        'payments.payments.reject',
                        'payments.payments.partial.approval',
                        'payments.reversals.accept',
                        'payments.reversals.reject',
                        'payments.captures.accept',
                        'payments.captures.review',
                        'payments.captures.reject',
                        'payments.refunds.accept',
                        'payments.refunds.reject',
                        'payments.refunds.partial.approval',
                        'payments.credits.accept',
                        'payments.credits.review',
                        'payments.credits.reject',
                        'payments.credits.partial.approval',
                        'payments.voids.accept',
                        'payments.voids.reject',
                    ]
                ],
                [
                    'productId' => 'fraudManagementEssentials',
                    'eventTypes' => [
                        'risk.profile.decision.review',
                        'risk.profile.decision.reject',
                        'risk.casemanagement.decision.accept',
                        'risk.casemanagement.decision.reject',
                    ]
                ],
                [
                    'productId' => 'alternativePaymentMethods',
                    'eventTypes' => [
                        'payments.payments.updated',
                    ]
                ]
            ],
            'webhookUrl' => $webhookUrl,
            'healthCheckUrl' => $healthCheckUrl,
            'notificationScope' => 'SELF',
            'securityPolicy' => [
                'securityType' => 'key',
                'keyId' => $sharedSecretKeyId
            ],
            'retryPolicy' => [
                'algorithm' => 'ARITHMETIC',
                'firstRetry' => 1,
                'interval' => 1,
                'numberOfRetries' => 3,
                'deactivateFlag' => 'false',
                'repeatSequenceCount' => 0,
                'repeatSequenceWaitTime' => 0
            ]
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
            }
        } catch (\Exception $e) {
            $output->writeln('Error creating webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}