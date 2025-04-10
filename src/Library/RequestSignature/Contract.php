<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestSignature;

interface Contract
{
    public function getHeadersForGetMethod(string $endpoint): array;
    public function getHeadersForPostMethod(string $endpoint, string $requestPayload): array;
    public function getHeadersForPutMethod(string $endpoint, string $requestPayload): array;
    public function getHeadersForPatchMethod(string $endpoint, string $requestPayload): array;
    public function getHeadersForDeleteMethod(string $endpoint): array;
}
