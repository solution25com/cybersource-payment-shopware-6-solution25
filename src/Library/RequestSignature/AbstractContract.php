<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestSignature;

use Symfony\Component\String\UnicodeString;

abstract class AbstractContract implements Contract
{
    protected const HEADER_KEYS_WITH_PAYLOAD = 'host date request-target digest v-c-merchant-id';
    protected const HEADER_KEYS_WITHOUT_PAYLOAD = 'host date request-target v-c-merchant-id';
    protected string $orgId;
    protected string $baseUrl;
    private string $host;
    private string $currentUTCDateTime;
    private array $defaultHeaders = [];

    abstract protected function hashSignature(string $signature, string $headerKeysString): UnicodeString;

    public function __construct()
    {
        $dateTime = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->currentUTCDateTime = $dateTime->format(DATE_RFC7231);
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $this->host = is_string($host) ? $host : '';
        $this->defaultHeaders = [
            'host' => $this->host,
            'Content-Type' => 'application/json',
            'v-c-merchant-id' => $this->orgId,
            'Date' => $this->currentUTCDateTime
        ];
    }

    public function getHeadersForGetMethod(string $endpoint): array
    {
        $signature = $this->getSignatureWithoutPayload('get', $endpoint);

        return array_merge(
            $this->defaultHeaders,
            ['signature' => $signature->toString()]
        );
    }

    public function getHeadersForPostMethod(string $endpoint, string $requestPayload): array
    {
        $payloadDigest = Digest::generate($requestPayload);
        $signature = $this->getSignatureWithPayload('post', $endpoint, $payloadDigest);

        return array_merge(
            $this->defaultHeaders,
            ['signature' => $signature->toString(), 'digest' => sprintf("%s=%s", 'SHA-256', $payloadDigest)]
        );
    }

    public function getHeadersForPutMethod(string $endpoint, string $requestPayload): array
    {
        $payloadDigest = Digest::generate($requestPayload);
        $signature = $this->getSignatureWithPayload('put', $endpoint, $payloadDigest);

        return array_merge(
            $this->defaultHeaders,
            ['signature' => $signature->toString(), 'digest' => sprintf("%s=%s", 'SHA-256', $payloadDigest)]
        );
    }

    public function getHeadersForPatchMethod(string $endpoint, string $requestPayload): array
    {
        $payloadDigest = Digest::generate($requestPayload);
        $signature = $this->getSignatureWithPayload('patch', $endpoint, $payloadDigest);

        return array_merge(
            $this->defaultHeaders,
            ['signature' => $signature->toString(), 'digest' => sprintf("%s=%s", 'SHA-256', $payloadDigest)]
        );
    }

    public function getHeadersForDeleteMethod(string $endpoint): array
    {
        $signature = $this->getSignatureWithoutPayload('delete', $endpoint);

        return array_merge(
            $this->defaultHeaders,
            ['signature' => $signature->toString()]
        );
    }

    protected function getSignatureWithoutPayload(string $method, string $endpoint): UnicodeString
    {
        $signatureString = sprintf(
            "host: %s\ndate: %s\nrequest-target: %s %s\nv-c-merchant-id: %s",
            $this->host,
            $this->currentUTCDateTime,
            $method,
            $endpoint,
            $this->orgId
        );

        return $this->hashSignature($signatureString, self::HEADER_KEYS_WITHOUT_PAYLOAD);
    }

    protected function getSignatureWithPayload(string $method, string $endpoint, string $payloadDigest): UnicodeString
    {
        $signatureString = sprintf(
            "host: %s\ndate: %s\nrequest-target: %s %s\ndigest: %s\nv-c-merchant-id: %s",
            $this->host,
            $this->currentUTCDateTime,
            $method,
            $endpoint,
            "SHA-256=" . $payloadDigest,
            $this->orgId
        );

        return $this->hashSignature($signatureString, self::HEADER_KEYS_WITH_PAYLOAD);
    }
}
