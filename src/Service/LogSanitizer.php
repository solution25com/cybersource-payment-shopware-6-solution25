<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

class LogSanitizer
{
    private const REDACTED = '[redacted]';

    public function sanitizeContext(array $context): array
    {
        return $this->sanitizeArray($context);
    }

    private function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $keyName = is_string($key) ? $key : null;

            if ($keyName !== null && $this->shouldRedactKey($keyName)) {
                $sanitized[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function shouldRedactKey(string $key): bool
    {
        return (bool) preg_match(
            '/authorization|signature|secret|token|jwt|payload|headers|customfields|billto|billingaddress|cards|customerid|customerinformation|instrumentidentifier|instrumentid/i',
            $key
        );
    }

    private function sanitizeString(string $value): string
    {
        if (str_starts_with(strtolower($value), 'bearer ')) {
            return self::REDACTED;
        }

        if (preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $value) === 1) {
            return self::REDACTED;
        }

        $digitsOnly = preg_replace('/\D+/', '', $value);
        if (is_string($digitsOnly) && strlen($digitsOnly) >= 12) {
            return self::REDACTED;
        }

        return $value;
    }
}
