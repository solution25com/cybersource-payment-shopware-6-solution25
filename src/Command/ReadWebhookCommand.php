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
            try {
                $orgId = $this->apiClient->getConfigurationService()->getOrganizationID();
                $response = $this->apiClient->readAllWebhook($orgId);
                $output->writeln('Webhook read response:');
                $output->writeln('Status Code: ' . $response['statusCode']);
                $output->writeln('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));
                //check body first element and update webhookId
                if (isset($response['body'][0]['webhookId'])) {
                    $webhookId = $response['body'][0]['webhookId'];
                    $this->systemConfigService->set('CyberSourceShopware6.config.webhookId', $webhookId);
                    $output->writeln('Webhook ID updated: ' . $webhookId);
                } else {
                    $output->writeln('No webhook found.');
                }
            } catch (\Exception $e) {
                $output->writeln('Error reading webhook: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }
        else {

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
        }

        return Command::SUCCESS;
    }
}