<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestSignature;

use Symfony\Component\String\UnicodeString;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class JWT extends AbstractContract
{
    protected string $p12;

    public function __construct(EnvironmentUrl $environmentUrl, string $orgId, string $p12)
    {
        $this->baseUrl = $environmentUrl->value;
        $this->orgId = $orgId;
        $this->p12 = $p12;
        parent::__construct();
    }

    protected function hashSignature(string $signatureString, string $headerKeysString): UnicodeString
    {
        return new UnicodeString('');
    }
}
