<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Exceptions;

use CyberSource\Shopware6\Exceptions\CyberSourceException;
use Symfony\Component\HttpFoundation\Response;

class APIException extends CyberSourceException
{
    public function __construct(
        protected string $orderTransactionId,
        protected string $errorCode,
        string $message = '',
        \Throwable $previous = null
    ) {
        parent::__construct($orderTransactionId, $message, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function shouldRaiseException(array $response): bool
    {
        return true;
    }
}
