<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateStatusWebhookCommand extends Command
{
    protected static $defaultName = 'cybersource:update-status-webhook';

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
        $this->setName('cybersource:update-status-webhook');
        $this->setDescription('Updates the status of an existing CyberSource webhook.');
        $this->addOption(
            'active',
            null,
            InputOption::VALUE_REQUIRED,
            'Set webhook status to ACTIVE (true) or INACTIVE (false)',
            'true'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');

        if (!$webhookId) {
            $output->writeln('Error: No webhook ID found in configuration. Please create a webhook first.');
            return Command::FAILURE;
        }

        $active = filter_var($input->getOption('active'), FILTER_VALIDATE_BOOLEAN);
        $status = $active ? 'ACTIVE' : 'INACTIVE';

        $payload = [
            'status' => $status
        ];

        $output->writeln('Updating CyberSource webhook with ID: ' . $webhookId . ' to status: ' . $status);

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