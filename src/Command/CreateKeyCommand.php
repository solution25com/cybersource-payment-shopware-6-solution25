<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\CyberSourceApiClient;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateKeyCommand extends Command
{
    protected static $defaultName = 'cybersource:create-key';

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
        $this->setName('cybersource:create-key');
        $this->setDescription('Creates a CyberSource shared secret key for webhook verification.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = [
            'clientRequestAction' => 'CREATE',
            'keyInformation' => [
                'provider' => 'nrtd',
                'tenant' => $this->apiClient->getConfigurationService()->getOrganizationID(),
                'keyType' => 'sharedSecret',
                'organizationId' => $this->apiClient->getConfigurationService()->getOrganizationID()
            ]
        ];

        $output->writeln('Creating CyberSource shared secret key...');

        try {
            $response = $this->apiClient->createKey($payload);
             if ($response['statusCode'] === 201) {
                if (isset($response['body']['status']) && $response['body']['status'] === 'SUCCESS') {
                    $keyId = $response['body']['keyInformation']['keyId'];
                    $key = $response['body']['keyInformation']['key'];
                    $this->systemConfigService->set('CyberSourceShopware6.config.sharedSecretKeyId', $keyId);
                    $this->systemConfigService->set('CyberSourceShopware6.config.sharedSecretKey', $key);
                    $output->writeln('Stored shared secret key in configuration. Key ID: ' . $keyId);
                }
            } else {
                $output->writeln('Failed to create key: ' . $response['statusMessage']);
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $output->writeln('Error creating key: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}