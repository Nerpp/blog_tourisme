<?php

namespace App\Tests\Functional;

final class GoogleControllerTest extends FunctionalTestCase
{
    public function testGoogleStartRedirectsToOAuthProvider(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://untrusted.example/connect/google');

        self::assertResponseRedirects(null, 302);
        $location = $client->getResponse()->headers->get('Location') ?? '';
        self::assertStringContainsString('accounts.google.com', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('https://estela-exploration.fr/connect/google/check', $query['redirect_uri'] ?? null);
    }

    public function testGoogleCallbackWithoutAuthenticatorDataRedirectsToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/connect/google/check');

        self::assertResponseRedirects('/login');
    }

    public function testGoogleCallbackErrorQueryRedirectsToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/connect/google/check?error=access_denied');

        self::assertResponseRedirects('/login');
    }
}
