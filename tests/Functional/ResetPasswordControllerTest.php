<?php

namespace App\Tests\Functional;

final class ResetPasswordControllerTest extends FunctionalTestCase
{
    public function testRequestResetPageIsAccessible(): void
    {
        $client = static::createClient();

        $client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Réinitialiser');
    }

    public function testUnknownEmailUsesGenericResetFlow(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/reset-password', [
            'reset_password_request_form' => [
                'email' => 'unknown-reset@example.test',
                '_token' => $this->inputValue($crawler, 'input[name="reset_password_request_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/reset-password/check-email');
    }

    public function testKnownEmailUsesSameGenericResetFlowWithoutExternalService(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $crawler = $client->request('GET', '/reset-password');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/reset-password', [
            'reset_password_request_form' => [
                'email' => $user->getEmail(),
                '_token' => $this->inputValue($crawler, 'input[name="reset_password_request_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/reset-password/check-email');
    }

    public function testResetWithoutTokenRedirectsToRequestPage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/reset-password/reset');

        self::assertResponseRedirects('/reset-password');
    }

    public function testInvalidResetTokenIsHandledCleanly(): void
    {
        $client = static::createClient();

        $client->request('GET', '/reset-password/reset/not-a-valid-token');
        self::assertResponseRedirects('/reset-password/reset');

        $client->request('GET', '/reset-password/reset');

        self::assertResponseRedirects('/reset-password');
    }
}
