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
    private readonly string $baseUri;

    public function __construct(string $baseUri, RequestSignatureContract $headerSignature)
    {
        $httpClient = HttpClient::create(['base_uri' => $baseUri]);
        $this->baseUri = $baseUri;
        $this->client = new ScopingHttpClient($httpClient, []);
        $this->headerSignature = $headerSignature;
    }

    public function getData(string $endpoint): array
    {
        $headers = $this->headerSignature->getHeadersForGetMethod($endpoint);
        $options = [
            'headers' => $headers
        ];
        $response = $this->client->request(Request::METHOD_GET, $endpoint, $options);

        return $response->toArray();
    }

    public function postData(string $endpoint, array $requestBody): array
    {
        $jsonRequestBody = json_encode($requestBody);
        if ($jsonRequestBody === false) {
            throw new \RuntimeException('Failed to encode request body to JSON');
        }
        $headers = $this->headerSignature->getHeadersForPostMethod(
            $endpoint,
            $jsonRequestBody
        );
        $options = [
            'headers' => $headers,
            'base_uri' => $this->baseUri,
            'body' => $jsonRequestBody
        ];
        $response = $this->client->request(Request::METHOD_POST, $endpoint, $options);
        return $response->toArray();
    }
}
