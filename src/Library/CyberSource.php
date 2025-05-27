<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Library;

use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuth;
use CyberSource\Shopware6\Library\RequestSignature\Contract as RequestSignatureContract;

class CyberSource
{
    private RestClient $apiClient;
    private const PAYMENT_URL = '/pts/v2/payments/';
    private const CAPTURE_PAYMENT_SUFFIX = '/captures';
    private const AUTH_REVERSAL_PAYMENT_SUFFIX = '/reversals';
    private const REFUND_PAYMENT_SUFFIX = '/refunds';

    public function __construct(EnvironmentUrl $environmentUrl, RequestSignatureContract $requestSignature)
    {
        $this->apiClient = new RestClient($environmentUrl->value, $requestSignature);
    }

    /**
     * @return array<string, mixed>
     */
    public function authAndCapturePaymentWithCreditCard(PaymentAuth $requestData): array
    {
        $responseFromAuthPayment = $this->authorizePaymentWithCreditCard($requestData);

        if (
            !empty($responseFromAuthPayment['status']) &&
            $responseFromAuthPayment['status'] === 'AUTHORIZED'
        ) {
            $transactionId = $responseFromAuthPayment['id'] ?? '';
            if (!$transactionId) {
                throw new \RuntimeException('Transaction ID not found in response.');
            }
            try {
                return $this->capturePaymentWithCreditCard($requestData, $transactionId);
            } catch (\Exception $exception) {
                $this->doAuthorizationReversal($requestData, $transactionId);
                throw $exception;
            }
        }

        return $responseFromAuthPayment;
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizePaymentWithCreditCard(PaymentAuth $requestData): array
    {
        return $this->apiClient->postData(self::PAYMENT_URL, $requestData->makePaymentRequest());
    }

    /**
     * @return array<string, mixed>
     */
    public function capturePaymentWithCreditCard(PaymentAuth $requestDataForCapture, string $captureId): array
    {
        return $this->apiClient->postData(
            self::PAYMENT_URL . $captureId . self::CAPTURE_PAYMENT_SUFFIX,
            $requestDataForCapture->makeCapturePaymentRequest()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function capturePayment(string $captureId, array $requestData): array
    {
        return $this->apiClient->postData(
            sprintf('%s%s%s', '/pts/v2/payments/', $captureId, '/captures'),
            $requestData
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function refundPayment(string $transactionId, array $requestData): array
    {
        return $this->apiClient->postData(
            sprintf('%s%s%s', '/pts/v2/payments/', $transactionId, self::REFUND_PAYMENT_SUFFIX),
            $requestData
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function doAuthorizationReversal(
        PaymentAuth $requestDataForAuthReversal,
        string $transactionId
    ): array {
        return $this->apiClient->postData(
            self::PAYMENT_URL . $transactionId . self::AUTH_REVERSAL_PAYMENT_SUFFIX,
            $requestDataForAuthReversal->makeAuthReversalPaymentRequest()
        );
    }
}