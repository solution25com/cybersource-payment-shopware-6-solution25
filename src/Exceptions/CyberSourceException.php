<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Exceptions;

use Shopware\Core\Checkout\Payment\PaymentException;

abstract class CyberSourceException extends PaymentException
{
    abstract public function shouldRaiseException(array $response): bool;
}
