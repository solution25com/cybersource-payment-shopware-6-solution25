<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;

class RestClient
{
    private HttpClientInterface $client;
    private readonly RequestSignatureContract $headerSignature;

    public function __construct(string $baseUri, RequestSignatureContract $headerSignature)
    {
        $httpClient = HttpClient::create(['base_uri' => $baseUri]);
        $this->client = new ScopingHttpClient($httpClient, []);
        $this->headerSignature = $headerSignature;
    }

    /**
     * @return array<string, mixed>
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     */
    public function getData(string $endpoint): array
    {
        $headers = $this->headerSignature->getHeadersForGetMethod($endpoint);
        $response = $this->client->request(Request::METHOD_GET, $endpoint, [
            'headers' => $headers,
        ]);

        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $requestBody
     * @return array<string, mixed>
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     */
    public function postData(string $endpoint, array $requestBody): array
    {
        $jsonRequestBody = json_encode($requestBody);
        if ($jsonRequestBody === false) {
            throw new \RuntimeException('Failed to encode request body to JSON');
        }
        $headers = $this->headerSignature->getHeadersForPostMethod($endpoint, $jsonRequestBody);
        $response = $this->client->request(Request::METHOD_POST, $endpoint, [
            'headers' => $headers,
            'body' => $jsonRequestBody,
        ]);

        return $response->toArray();
    }
}