<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class CyberSourceApiClient
{
    private ConfigurationService $configurationService;
    private LoggerInterface $logger;

    public function __construct(
        ConfigurationService $configurationService,
        LoggerInterface $logger
    ) {
        $this->configurationService = $configurationService;
        $this->logger = $logger;
    }

    public function createWebhook(array $payload, ?string $salesChannelId = null): array
    {
        $endpoint = '/notification-subscriptions/v1/webhooks';
        $payloadJson = json_encode($payload);
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForPostMethod($endpoint, $payloadJson);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);
        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson
            ]);

            $responseBody = $response->getBody()->getContents();

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => json_decode($responseBody, true)
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create CyberSource webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
            throw new \RuntimeException('Failed to create webhook: ' . $e->getMessage());
        }
    }

    public function readWebhook(string $webhookId): array
    {
        $endpoint = '/notification-subscriptions/v1/webhooks/' . $webhookId;
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForGetMethod($endpoint);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->get($endpoint, [
                'headers' => $headers
            ]);

            $responseBody = $response->getBody()->getContents();

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => json_decode($responseBody, true)
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to read CyberSource webhook', [
                'error' => $e->getMessage(),
                'webhookId' => $webhookId,
            ]);
            throw new \RuntimeException('Failed to read webhook: ' . $e->getMessage());
        }
    }

    public function updateWebhook(string $webhookId, array $payload): array
    {
        $endpoint = '/notification-subscriptions/v1/webhooks/' . $webhookId;
        $payloadJson = json_encode($payload);
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForPatchMethod($endpoint, $payloadJson);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->patch($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson
            ]);

            $responseBody = $response->getBody()->getContents();

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => json_decode($responseBody, true)
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to update CyberSource webhook', [
                'error' => $e->getMessage(),
                'webhookId' => $webhookId,
                'payload' => $payload,
            ]);
            throw new \RuntimeException('Failed to update webhook: ' . $e->getMessage());
        }
    }

    public function deleteWebhook(string $webhookId): array
    {
        $endpoint = '/notification-subscriptions/v1/webhooks/' . $webhookId;
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForDeleteMethod($endpoint);
        $base_url = $this->configurationService->getBaseUrl()->value;
        $client = new Client(['base_uri' => $base_url]);

        try {
            $response = $client->delete($endpoint, [
                'headers' => $headers
            ]);

            return [
                'statusCode' => $response->getStatusCode(),
                'body' => []
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to delete CyberSource webhook', [
                'error' => $e->getMessage(),
                'webhookId' => $webhookId,
            ]);
            throw new \RuntimeException('Failed to delete webhook: ' . $e->getMessage());
        }
    }

    public function capturePayment(string $transactionId, array $payload): array
    {
        $baseUrlObject = $this->configurationService->getBaseUrl();
        $baseUrl = $baseUrlObject->value;
        $endpoint = '/pts/v2/payments/' . $transactionId . '/captures';

        $payloadJson = json_encode($payload);
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForPostMethod($endpoint, $payloadJson);

        $client = new Client(['base_uri' => $baseUrl]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson,
            ]);

            $responseBody = $response->getBody()->getContents();
            return [
                'statusCode' => $response->getStatusCode(),
                'body' => json_decode($responseBody, true),
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to capture payment: ' . $e->getMessage());
        }
    }

    public function voidPayment(string $transactionId, array $payload): array
    {
        $baseUrlObject = $this->configurationService->getBaseUrl();
        $baseUrl = $baseUrlObject->value;
        $endpoint = '/pts/v2/payments/' . $transactionId . '/voids';

        $payloadJson = json_encode($payload);
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForPostMethod($endpoint, $payloadJson);

        $client = new Client(['base_uri' => $baseUrl]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson,
            ]);

            $responseBody = $response->getBody()->getContents();
            return [
                'statusCode' => $response->getStatusCode(),
                'body' => json_decode($responseBody, true),
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to void payment: ' . $e->getMessage());
        }
    }

    public function refundPayment(string $transactionId, array $payload): array
    {
        $baseUrlObject = $this->configurationService->getBaseUrl();
        $baseUrl = $baseUrlObject->value;
        $endpoint = '/pts/v2/payments/' . $transactionId . '/refunds';

        $payloadJson = json_encode($payload);
        $signatureContract = $this->configurationService->getSignatureContract();
        $headers = $signatureContract->getHeadersForPostMethod($endpoint, $payloadJson);

        $client = new Client(['base_uri' => $baseUrl]);

        try {
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => $payloadJson,
            ]);

            $responseBody = $response->getBody()->getContents();
            return [
                'statusCode' => $response->getStatusCode(),
                'body' => json_decode($responseBody, true),
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to refund payment: ' . $e->getMessage());
        }
    }

    public function getConfigurationService(): ConfigurationService
    {
        return $this->configurationService;
    }
}