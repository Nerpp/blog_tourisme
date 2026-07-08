<?php

namespace App\Service\Media;

use InvalidArgumentException;

final class PublicMediaPathValidator
{
    public function validateNullableUploadPath(?string $path, string $fieldName): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (!$this->isSafeUploadPath($path)) {
            throw new InvalidArgumentException(sprintf('Le champ %s doit être un chemin public valide dans /uploads/.', $fieldName));
        }

        return $path;
    }

    public function validateNullableHttpUrl(?string $url, string $fieldName): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null;

        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException(sprintf('Le champ %s doit être une URL HTTP ou HTTPS valide.', $fieldName));
        }

        return $url;
    }

    public function isSafeMediaUploadPath(?string $path): bool
    {
        $path = trim((string) $path);

        return $this->isSafeUploadPath($path) && str_starts_with($path, '/uploads/media/');
    }

    private function isSafeUploadPath(string $path): bool
    {
        return $path !== ''
            && str_starts_with($path, '/uploads/')
            && !str_contains($path, '..')
            && !str_contains($path, '\\')
            && !str_contains($path, '//')
            && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $path);
    }
}
