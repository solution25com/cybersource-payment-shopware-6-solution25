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

    /**
     * __construct
     *
     * @param  EnvironmentUrl $environmentUrl
     * @param  RequestSignatureContract $requestSignature
     * @return void
     */
    public function __construct(EnvironmentUrl $environmentUrl, RequestSignatureContract $requestSignature)
    {
        $this->apiClient = new RestClient($environmentUrl->value, $requestSignature);
    }

    /**
     * authAndCapturePaymentWithCreditCard
     *
     * @param  PaymentAuth $requestData
     * @return array
     */
    public function authAndCapturePaymentWithCreditCard(PaymentAuth $requestData): array
    {
        $responseFromAuthPayment = $this->authorizePaymentWithCreditCard($requestData);

        if (
            !empty($responseFromAuthPayment['status']) &&
            $responseFromAuthPayment['status'] === 'AUTHORIZED'
        ) {
            $transactionId = $responseFromAuthPayment['id'];
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
     * authorizePaymentWithCreditCard
     *
     * @param  PaymentAuth $requestData
     * @return array
     */
    public function authorizePaymentWithCreditCard(PaymentAuth $requestData): array
    {
        return $this->apiClient->postData(self::PAYMENT_URL, $requestData->makePaymentRequest());
    }

    /**
     * capturePaymentWithCreditCard
     *
     * @param  PaymentAuth $requestDataForCapture
     * @param  string $captureId
     * @return array
     */
    public function capturePaymentWithCreditCard(PaymentAuth $requestDataForCapture, string $captureId): array
    {
        return $this->apiClient->postData(
            self::PAYMENT_URL . $captureId . self::CAPTURE_PAYMENT_SUFFIX,
            $requestDataForCapture->makeCapturePaymentRequest()
        );
    }

    public function capturePayment(string $captureId, array $requestData): array
    {
        return $this->apiClient->postData(
            sprintf('%s%s%s', '/pts/v2/payments/', $captureId, '/captures'),
            $requestData
        );
    }

    public function refundPayment(string $transactionId, array $requestData): array
    {
        return $this->apiClient->postData(
            sprintf('%s%s%s', '/pts/v2/payments/', $transactionId, self::REFUND_PAYMENT_SUFFIX),
            $requestData
        );
    }

    /**
     * doAuthorizationReversal
     *
     * @param PaymentAuth $requestDataForAuthReversal
     * @param string $transactionId
     * @return array
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
