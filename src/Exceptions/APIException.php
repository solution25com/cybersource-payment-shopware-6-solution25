<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Checkout\Payment\PaymentException;
use CyberSource\Shopware6\Exceptions\CyberSourceException;

class APIException extends CyberSourceException
{
    public function __construct(
        protected string $orderTransactionId,
        protected string $errorCode,
        string $message = '',
        \Throwable $previous = null
    ) {
        $exception = PaymentException::syncProcessInterrupted($orderTransactionId, $message);
        parent::__construct($exception->getCode(), $this->getErrorCode(), $exception->getMessage(), [], $previous);
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
