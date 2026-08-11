<?php

namespace App\Entity\Traits;

trait InstagramErrorTrait
{
    private static function normalizeInstagramText(?string $value, ?int $maximumLength = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $maximumLength === null ? $value : mb_substr($value, 0, $maximumLength);
    }

    private static function sanitizeInstagramError(?string $error): ?string
    {
        $error = self::normalizeInstagramText($error);
        if ($error === null) {
            return null;
        }

        $error = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $error) ?? $error;
        $error = preg_replace(
            '/([?&](?:access[_-]?token|token|client[_-]?secret|authorization)=)[^&\s]*/i',
            '$1[redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/\b(access[_-]?token|token|client[_-]?secret|authorization)\b\s*[:=]\s*[^\s,;&]+/i',
            '$1=[redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $error) ?? $error;

        return mb_substr(trim($error), 0, 2000);
    }
}
