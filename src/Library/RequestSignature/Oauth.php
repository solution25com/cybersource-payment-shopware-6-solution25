<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestSignature;

use Symfony\Component\String\UnicodeString;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

final class Oauth extends AbstractContract
{
    protected string $accessToken;

    public function __construct(EnvironmentUrl $environmentUrl, string $orgId, string $accessToken)
    {
        $this->baseUrl = $environmentUrl->value;
        $this->orgId = $orgId;
        $this->accessToken = $accessToken;
        parent::__construct();
    }

    protected function hashSignature(string $signatureString, string $headerKeysString): UnicodeString
    {
        return new UnicodeString('');
    }
}
