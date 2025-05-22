<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestSignature;

use Symfony\Component\String\UnicodeString;
use CyberSource\Shopware6\Library\Constants\Hash;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

use function Symfony\Component\String\s;

final class HTTP extends AbstractContract
{
    protected string $accessKey;
    protected string $secretKey;
    public function __construct(EnvironmentUrl $environmentUrl, string $orgId, string $accessKey, string $secretKey)
    {
        $this->baseUrl = $environmentUrl->value;
        $this->orgId = $orgId;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        parent::__construct();
    }

    protected function hashSignature(string $signatureString, string $headerKeysString): UnicodeString
    {
        $decodedKey = base64_decode($this->secretKey);
        $signature = base64_encode(
            hash_hmac(Hash::SHA256, $signatureString, $decodedKey, true)
        );
        $signatureHeader = [
            sprintf("keyid=%s", '"' . $this->accessKey . '"'),
            sprintf("algorithm=%s", '"' . Hash::HMACSHA256 . '"'),
            sprintf("headers=%s", '"' . $headerKeysString . '"'),
            sprintf("signature=%s", '"' . $signature . '"')
        ];
        return new UnicodeString(implode(',', $signatureHeader));
    }
}
