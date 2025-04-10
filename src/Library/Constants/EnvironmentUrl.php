<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library\Constants;

enum EnvironmentUrl: string
{
    case TEST = "https://apitest.cybersource.com/";
    case PRODUCTION = "https://api.cybersource.com/";
}
