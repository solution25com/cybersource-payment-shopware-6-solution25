<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Exceptions;

interface ExceptionFactoryContract
{
    public function registerException(CyberSourceException $exception): void;
    public function raiseMatchingException(): void;
}
