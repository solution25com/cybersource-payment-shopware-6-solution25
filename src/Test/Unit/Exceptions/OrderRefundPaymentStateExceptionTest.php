<?php

namespace CyberSource\Shopware6\Test\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use CyberSource\Shopware6\Exceptions\OrderRefundPaymentStateException;

class OrderRefundPaymentStateExceptionTest extends TestCase
{
    public function testConstructor()
    {
        $exception = new OrderRefundPaymentStateException();
        $this->assertInstanceOf(OrderRefundPaymentStateException::class, $exception);
        $this->assertSame(
            'cybersource_shopware6.exception.REFUND_TRANSACTION_NOT_ALLOWED',
            $exception->getMessage()
        );
    }

    public function testGetStatusCode()
    {
        $exception = new OrderRefundPaymentStateException();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
    }

    public function testGetErrorCode()
    {
        $exception = new OrderRefundPaymentStateException();
        $this->assertSame('REFUND_TRANSACTION_NOT_ALLOWED', $exception->getErrorCode());
    }
}
