<?php

namespace App\Tests\Functional;

final class GoogleControllerTest extends FunctionalTestCase
{
    public function testGoogleStartRedirectsToOAuthProvider(): void
    {
        $client = static::createClient();

        $client->request('GET', '/connect/google');

        self::assertResponseRedirects(null, 302);
        self::assertStringContainsString('accounts.google.com', $client->getResponse()->headers->get('Location') ?? '');
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
