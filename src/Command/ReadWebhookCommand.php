<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReadWebhookCommand extends Command
{
    protected static $defaultName = 'cybersource:read-webhook';

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
        $this->setName('cybersource:read-webhook');
        $this->setDescription('Reads the details of a CyberSource webhook.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');

        if (!$webhookId) {
            $output->writeln('Error: No webhook ID found in configuration. Please create a webhook first.');
            return Command::FAILURE;
        }

        $output->writeln('Reading CyberSource webhook with ID: ' . $webhookId);

        try {
            $response = $this->apiClient->readWebhook($webhookId);
            $output->writeln('Webhook read response:');
            $output->writeln('Status Code: ' . $response['statusCode']);
            $output->writeln('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $output->writeln('Error reading webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}