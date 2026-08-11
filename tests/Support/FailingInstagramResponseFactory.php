<?php

namespace App\Tests\Support;

final class FailingInstagramResponseFactory
{
    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): never
    {
        throw new \LogicException(sprintf(
            'Un appel HTTP Instagram inattendu a été bloqué en environnement de test (%s %s).',
            $method,
            parse_url($url, PHP_URL_HOST) ?: 'hôte inconnu',
        ));
    }
}
