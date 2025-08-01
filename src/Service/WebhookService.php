<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Style\SymfonyStyle;

class WebhookService
{
    private CyberSourceApiClient $apiClient;
    private SystemConfigService $systemConfigService;
    private UrlService $urlService;

    public function __construct(
        CyberSourceApiClient $apiClient,
        SystemConfigService $systemConfigService,
        UrlService $urlService
    ) {
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
        $this->urlService = $urlService;
    }

    public function createKey(SymfonyStyle $io): bool
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

        $io->text('Creating CyberSource shared secret key...');

        try {
            $response = $this->apiClient->createKey($payload);
            if (
                $response['statusCode'] === 201 &&
                isset($response['body']['status']) &&
                $response['body']['status'] === 'SUCCESS'
            ) {
                $keyId = $response['body']['keyInformation']['keyId'];
                $key = $response['body']['keyInformation']['key'];
                $this->systemConfigService->set('CyberSourceShopware6.config.sharedSecretKeyId', $keyId);
                $this->systemConfigService->set('CyberSourceShopware6.config.sharedSecretKey', $key);
                $io->success('Stored shared secret key in configuration. Key ID: ' . $keyId);
                return true;
            }
            $io->error('Failed to create key: ' . ($response['statusMessage'] ?? 'Unknown error'));
            return false;
        } catch (\Exception $e) {
            $io->error('Error creating key: ' . $e->getMessage());
            return false;
        }
    }

    public function createWebhook(string $name, string $webhookUrl, string $healthCheckUrl, SymfonyStyle $io): bool
    {
        $sharedSecretKeyId = $this->systemConfigService->get('CyberSourceShopware6.config.sharedSecretKeyId');
        if (!$sharedSecretKeyId) {
            $io->error('Shared secret key not found. Run "cybersource:create-key" first.');
            return false;
        }

        $payload = [
            'name' => $name,
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

        $io->text('Creating CyberSource webhook...');
        try {
            $response = $this->apiClient->createWebhook($payload);
            $io->text('Webhook creation response:');
            $io->text('Status Code: ' . $response['statusCode']);
            $io->text('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));

            if ($response['statusCode'] === 201 && isset($response['body']['webhookId'])) {
                $webhookId = $response['body']['webhookId'];
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookId', $webhookId);
                $io->success('Stored webhook ID in configuration: ' . $webhookId);
                return true;
            }
            $io->error('Webhook creation failed.');
            return false;
        } catch (\Exception $e) {
            $io->error('Error creating webhook: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteWebhook(SymfonyStyle $io): bool
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');
        if (!is_string($webhookId)) {
            $io->error('No valid webhook ID found in configuration. Please create a webhook first.');
            return false;
        }

        $io->text('Deleting CyberSource webhook with ID: ' . $webhookId);

        try {
            $response = $this->apiClient->deleteWebhook($webhookId);
            $io->text('Webhook deletion response:');
            $io->text('Status Code: ' . $response['statusCode']);

            if ($response['statusCode'] === 204) {
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookId', null);
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookSharedSecret', null);
                $io->success('Cleared webhook ID and shared secret from configuration.');
                return true;
            }
            $io->error('Webhook deletion failed.');
            return false;
        } catch (\Exception $e) {
            $io->error('Error deleting webhook: ' . $e->getMessage());
            return false;
        }
    }

    public function readWebhook(SymfonyStyle $io): bool
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');

        if (empty($webhookId) || !is_string($webhookId)) {
            try {
                $orgId = $this->apiClient->getConfigurationService()->getOrganizationID();
                $response = $this->apiClient->readAllWebhook($orgId);
                $io->text('Webhook read response:');
                $io->text('Status Code: ' . $response['statusCode']);
                $io->text('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));

                if (isset($response['body'][0]['webhookId']) && is_string($response['body'][0]['webhookId'])) {
                    $webhookId = $response['body'][0]['webhookId'];
                    $this->systemConfigService->set('CyberSourceShopware6.config.webhookId', $webhookId);
                    $io->success('Webhook ID updated: ' . $webhookId);
                    return true;
                }
                $io->warning('No webhook found.');
                return false;
            } catch (\Exception $e) {
                $io->error('Error reading webhook: ' . $e->getMessage());
                return false;
            }
        }

        if (!is_string($webhookId)) {
            $io->error('Webhook ID is not a valid string.');
            return false;
        }

        $io->text('Reading CyberSource webhook with ID: ' . $webhookId);

        try {
            $response = $this->apiClient->readWebhook($webhookId);
            $io->text('Webhook read response:');
            $io->text('Status Code: ' . $response['statusCode']);
            $io->text('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));
            return true;
        } catch (\Exception $e) {
            $io->error('Error reading webhook: ' . $e->getMessage());
            return false;
        }
    }

    public function updateWebhookStatus(bool $active, SymfonyStyle $io): bool
    {
        $webhookId = $this->systemConfigService->get('CyberSourceShopware6.config.webhookId');
        if (!is_string($webhookId)) {
            $io->error('No valid webhook ID found in configuration. Please create a webhook first.');
            return false;
        }

        $status = $active ? 'ACTIVE' : 'INACTIVE';
        $payload = ['status' => $status];

        $io->text('Updating CyberSource webhook with ID: ' . $webhookId . ' to status: ' . $status);

        try {
            $response = $this->apiClient->updateWebhook($webhookId, $payload);
            $io->text('Webhook update response:');
            $io->text('Status Code: ' . $response['statusCode']);
            $io->text('Body: ' . json_encode($response['body'], JSON_PRETTY_PRINT));

            if ($response['statusCode'] === 200 && isset($response['body']['securityPolicy']['sharedSecret'])) {
                $sharedSecret = $response['body']['securityPolicy']['sharedSecret'];
                $this->systemConfigService->set('CyberSourceShopware6.config.webhookSharedSecret', $sharedSecret);
                $io->success('Updated webhook shared secret in configuration.');
            }
            return true;
        } catch (\Exception $e) {
            $io->error('Error updating webhook: ' . $e->getMessage());
            return false;
        }
    }

    public function getWebhookUrl(Context $context): string
    {
        $shopBaseUrl = $this->urlService->getShopwareBaseUrl('default', $context);
        return $shopBaseUrl . '/cybersource/webhook';
    }

    public function getHealthCheckUrl(Context $context): string
    {
        $shopBaseUrl = $this->urlService->getShopwareBaseUrl('default', $context);
        return $shopBaseUrl . '/cybersource/webhook/health';
    }
}
