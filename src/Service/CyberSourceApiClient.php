<?php

namespace CyberSource\Shopware6\Service;

use GuzzleHttp\Client;

class CyberSourceApiClient
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://apitest.cybersource.com',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'v-c-merchant-id' => 'your_merchant_id',
                'Date' => gmdate('D, d M Y H:i:s T'),
                'Host' => 'apitest.cybersource.com',
                'Authorization' => 'your_signature_header',
            ]
        ]);
    }

    public function post(string $endpoint, array $body): array
    {
        $response = $this->client->post($endpoint, [
            'json' => $body
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}
