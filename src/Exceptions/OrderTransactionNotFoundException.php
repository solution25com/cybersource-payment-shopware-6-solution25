<?php

namespace CyberSource\Shopware6\Exceptions;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class OrderTransactionNotFoundException extends ShopwareHttpException
{
    protected string $errorCode;
    public function __construct(
        $message,
        $errorCode
    ) {
        parent::__construct(
            $message
        );
        $this->errorCode = $errorCode;
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
