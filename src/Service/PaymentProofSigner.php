<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

class PaymentProofSigner
{
    private const DEFAULT_TTL_SECONDS = 7200;
    private const VERSION = 1;

    public function __construct(
        private readonly string $secret
    ) {
    }

    public function sign(array $payload, ?int $ttl = null): string
    {
        $envelope = [
            'version' => self::VERSION,
            'expiresAt' => time() + ($ttl ?? self::DEFAULT_TTL_SECONDS),
            'payload' => $payload,
        ];

        $encodedEnvelope = $this->base64UrlEncode(json_encode($envelope, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedEnvelope, $this->secret, true));

        return $encodedEnvelope . '.' . $signature;
    }

    public function verify(?string $token): ?array
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedEnvelope, $encodedSignature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedEnvelope, $this->secret, true));
        if (!hash_equals($expectedSignature, $encodedSignature)) {
            return null;
        }

        $decodedEnvelope = $this->base64UrlDecode($encodedEnvelope);
        if ($decodedEnvelope === null) {
            return null;
        }

        try {
            $envelope = json_decode($decodedEnvelope, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($envelope)) {
            return null;
        }

        $version = $envelope['version'] ?? null;
        $expiresAt = $envelope['expiresAt'] ?? null;
        $payload = $envelope['payload'] ?? null;

        if ($version !== self::VERSION || !is_int($expiresAt) || $expiresAt < time() || !is_array($payload)) {
            return null;
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
