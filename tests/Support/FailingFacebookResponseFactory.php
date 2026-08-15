<?php

namespace App\Tests\Support;

final class FailingFacebookResponseFactory
{
    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): never
    {
        throw new \LogicException(sprintf(
            'Un appel HTTP Facebook inattendu a été bloqué en environnement de test (%s %s).',
            $method,
            parse_url($url, PHP_URL_HOST) ?: 'hôte inconnu',
        ));
    }
}
