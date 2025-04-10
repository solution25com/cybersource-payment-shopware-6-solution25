<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Test\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use CyberSource\Shopware6\Exceptions\APIException;
use CyberSource\Shopware6\Exceptions\ExceptionFactory;
use CyberSource\Shopware6\Exceptions\RejectedException;
use CyberSource\Shopware6\Exceptions\DeclinedException;
use CyberSource\Shopware6\Exceptions\BadRequestException;
use CyberSource\Shopware6\Exceptions\CyberSourceException;
use CyberSource\Shopware6\Exceptions\RiskDeclinedException;
use CyberSource\Shopware6\Exceptions\PendingReviewException;
use CyberSource\Shopware6\Exceptions\InvalidRequestException;

class ExceptionFactoryTest extends TestCase
{
    public function testRaiseMatchingException(): void
    {
        $orderTransactionId = 'orderTransactionId';
        $response = [
            'status' => 'INVALID_REQUEST',
            'errorInformation' => [
                'reason' => 'MISSING_FIELD',
                'message' => 'Field is missing.',
            ],
        ];

        $exceptionFactory = new ExceptionFactory($orderTransactionId, $response);

        $pendingReviewException = $this->createMock(PendingReviewException::class);
        $rejectedException = $this->createMock(RejectedException::class);
        $invalidRequestException = $this->createMock(InvalidRequestException::class);
        $declinedException = $this->createMock(DeclinedException::class);
        $riskDeclinedException = $this->createMock(RiskDeclinedException::class);
        $badRequestException = $this->createMock(BadRequestException::class);

        $pendingReviewException->method('shouldRaiseException')->willReturn(false);
        $rejectedException->method('shouldRaiseException')->willReturn(false);
        $invalidRequestException->method('shouldRaiseException')->willReturn(true);
        $declinedException->method('shouldRaiseException')->willReturn(false);
        $riskDeclinedException->method('shouldRaiseException')->willReturn(false);
        $badRequestException->method('shouldRaiseException')->willReturn(false);

        $exceptionFactory->registerException($pendingReviewException);
        $exceptionFactory->registerException($rejectedException);
        $exceptionFactory->registerException($invalidRequestException);
        $exceptionFactory->registerException($declinedException);
        $exceptionFactory->registerException($riskDeclinedException);
        $exceptionFactory->registerException($badRequestException);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Field is missing.');

        $exceptionFactory->raiseMatchingException();
    }

    public function testRaiseMatchingExceptionWithDefaultMessage(): void
    {
        $orderTransactionId = 'orderTransactionId';
        $response = [
            'status' => 'UNKNOWN_STATUS',
            'errorInformation' => [
                'reason' => 'UNKNOWN_REASON',
                'message' => 'Unknown error occurred.',
            ],
        ];

        $exceptionFactory = new ExceptionFactory($orderTransactionId, $response);

        $cyberSourceException = $this->createMock(CyberSourceException::class);

        $cyberSourceException->method('shouldRaiseException')->willReturn(false);

        $exceptionFactory->registerException($cyberSourceException);

        $this->expectException(APIException::class);
        $this->expectExceptionMessage('An error occurred while processing the payment request.');

        $exceptionFactory->raiseMatchingException();
    }

    public function testShouldRaiseAPIException(): void
    {
        $exception = new APIException('orderTransactionId', 'errorCode');

        $response = ['status' => 500];

        $this->assertTrue($exception->shouldRaiseException($response));
    }
}
