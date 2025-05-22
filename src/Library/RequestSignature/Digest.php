<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\RequestSignature;

use CyberSource\Shopware6\Library\Constants\Hash;

final class Digest
{
    public static function generate(string $requestPayload): string
    {
        $digestEncode = hash(Hash::SHA256, $requestPayload, true);

        return base64_encode($digestEncode);
    }
}
