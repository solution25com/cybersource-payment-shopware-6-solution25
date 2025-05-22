<?php

namespace CyberSource\Shopware6\Library;

use CyberSource\Shopware6\Library\CyberSource;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;

class CyberSourceFactory
{
    public function createCyberSource(
        EnvironmentUrl $environmentUrl,
        RequestSignatureContract $requestSignature
    ): CyberSource {
        return new CyberSource($environmentUrl, $requestSignature);
    }
}
