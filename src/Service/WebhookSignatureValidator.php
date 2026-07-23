<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Psr\Log\LoggerInterface;

class WebhookSignatureValidator
{
    private const REPLAY_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function verify(
        string $payload,
        ?string $signatureHeader,
        ?string $sharedSecretKey,
        ?string $expectedKeyId = null,
        ?int $currentTimestamp = null
    ): bool {
        if (!$signatureHeader || !$sharedSecretKey) {
            return false;
        }

        $signatureParts = $this->parseSignatureHeader($signatureHeader);
        if ($signatureParts === null) {
            return false;
        }

        if ($expectedKeyId !== null && $expectedKeyId !== '' && $signatureParts['keyId'] !== $expectedKeyId) {
            $this->logger->warning('Webhook signature key id does not match configured value');
            return false;
        }

        $timestamp = $signatureParts['timestamp'];
        $now = $currentTimestamp ?? time();
        if (abs($now - $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            $this->logger->warning('Webhook signature timestamp is outside the replay window');
            return false;
        }

        $decodedSecret = base64_decode($sharedSecretKey, true);
        if ($decodedSecret === false) {
            $this->logger->error('Webhook shared secret key is not valid base64');
            return false;
        }

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $timestamp . '.' . $payload, $decodedSecret, true)
        );

        return hash_equals($expectedSignature, $signatureParts['signature']);
    }

    /**
     * @return array{timestamp:int,keyId:string,signature:string}|null
     */
    public function parseSignatureHeader(string $signatureHeader): ?array
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $keyValue = explode('=', trim($segment), 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            $parts[$keyValue[0]] = trim($keyValue[1], " \t\n\r\0\x0B\"");
        }

        $timestamp = $parts['t'] ?? null;
        $keyId = $parts['keyId'] ?? $parts['keyid'] ?? null;
        $signature = $parts['sig'] ?? null;
        if (!is_string($timestamp) || !ctype_digit($timestamp) || !is_string($keyId) || !is_string($signature)) {
            return null;
        }

        return [
            'timestamp' => (int) $timestamp,
            'keyId' => $keyId,
            'signature' => $signature,
        ];
    }
}
