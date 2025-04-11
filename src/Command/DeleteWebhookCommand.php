<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteWebhookCommand extends Command
{
    protected static $defaultName = 'cybersource:delete-webhook';

    private CyberSourceApiClient $apiClient;
    private SystemConfigService $systemConfigService;

    public function __construct(
        CyberSourceApiClient $apiClient,
        SystemConfigService $systemConfigService
    ) {
        parent::__construct();
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
    }

    protected function configure(): void
    {
        $this->setName('cybersource:delete-webhook');
        $this->setDescription('Deletes an existing CyberSource webhook.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');

        if (!$webhookId) {
            $output->writeln('Error: No webhook ID found in configuration. Please create a webhook first.');
            return Command::FAILURE;
        }

        $output->writeln('Deleting CyberSource webhook with ID: ' . $webhookId);

        try {
            $response = $this->apiClient->deleteWebhook($webhookId);
            $output->writeln('Webhook deletion response:');
            $output->writeln('Status Code: ' . $response['statusCode']);

            if ($response['statusCode'] === 204) {
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookId', null);
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookSharedSecret', null);
                $output->writeln('Cleared webhook ID and shared secret from configuration.');
            }
        } catch (\Exception $e) {
            $output->writeln('Error deleting webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}