<?php

namespace CyberSource\Shopware6\Exceptions;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class OrderRefundPaymentStateException extends ShopwareHttpException
{
    public function __construct()
    {
        $message = sprintf('cybersource_shopware6.exception.REFUND_TRANSACTION_NOT_ALLOWED');
        parent::__construct(
            $message
        );
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    public function getErrorCode(): string
    {
        return 'REFUND_TRANSACTION_NOT_ALLOWED';
    }
}
