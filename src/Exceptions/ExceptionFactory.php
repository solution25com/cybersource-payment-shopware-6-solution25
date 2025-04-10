<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Exceptions;

class ExceptionFactory implements ExceptionFactoryContract
{
    private array $exceptions = [];
    private string $orderTransactionId;
    private array $response;

    public function __construct(string $orderTransactionId, array $response)
    {
        $this->orderTransactionId = $orderTransactionId;
        $this->response = $response;

        $status = $response['status'];
        $reason = $response['errorInformation']['reason'] ?? 'API_ERROR';
        $message = $response['errorInformation']['message'] ?? '';

        $this->registerException(new PendingReviewException($orderTransactionId, 'GENERAL_DECLINE', $message));
        $this->registerException(new RejectedException($orderTransactionId, 'GENERAL_DECLINE', $message));
        $this->registerException(new InvalidRequestException($orderTransactionId, $reason, $message));
        $this->registerException(new DeclinedException($orderTransactionId, $reason, $message));
        $this->registerException(new RiskDeclinedException($orderTransactionId, $reason, $message));
        $this->registerException(new BadRequestException($orderTransactionId, $reason, $message));
    }

    public function registerException(CyberSourceException $exception): void
    {
        $this->exceptions[] = $exception;
    }

    public function raiseMatchingException(): void
    {
        foreach ($this->exceptions as $exception) {
            if ($exception->shouldRaiseException($this->response)) {
                throw $exception;
            }
        }

        // Default message for logging purposes only, not displayed to the user
        throw new APIException(
            $this->orderTransactionId,
            'API_ERROR',
            'An error occurred while processing the payment request.'
        );
    }
}
