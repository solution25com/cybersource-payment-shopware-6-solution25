<?php

namespace CyberSource\Shopware6\Test\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use CyberSource\Shopware6\Exceptions\OrderTransactionNotFoundException;

class OrderTransactionNotFoundExceptionTest extends TestCase
{
    public function testConstructor()
    {
        $message = 'Transaction not found';
        $errorCode = 'TRANSACTION_NOT_FOUND';
        $exception = new OrderTransactionNotFoundException($message, $errorCode);

        $this->assertInstanceOf(OrderTransactionNotFoundException::class, $exception);
        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($errorCode, $exception->getErrorCode());
    }

    public function testGetStatusCode()
    {
        $exception = new OrderTransactionNotFoundException('Transaction not found', 'TRANSACTION_NOT_FOUND');
        $this->assertSame(Response::HTTP_NOT_FOUND, $exception->getStatusCode());
    }

    public function testGetErrorCode()
    {
        $errorCode = 'TRANSACTION_NOT_FOUND';
        $exception = new OrderTransactionNotFoundException('Transaction not found', $errorCode);
        $this->assertSame($errorCode, $exception->getErrorCode());
    }
}
