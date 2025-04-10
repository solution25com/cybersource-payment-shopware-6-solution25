<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Exceptions;

use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;

abstract class CyberSourceException extends SyncPaymentProcessException
{
    abstract public function shouldRaiseException(array $response): bool;
}
