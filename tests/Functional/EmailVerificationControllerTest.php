<?php

namespace App\Tests\Functional;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class EmailVerificationControllerTest extends FunctionalTestCase
{
    public function testInvalidVerificationLinkRedirectsToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/verify/email?id=999999&hash=invalid');

        self::assertResponseRedirects('/login');
    }

    public function testAnonymousVisitorIsRedirectedFromResendPage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/verify/resend');

        self::assertResponseRedirects('/login');
    }

    public function testAlreadyVerifiedUserResendRedirectsToProfile(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(verified: true));

        $client->request('GET', '/verify/resend');

        self::assertResponseRedirects('/profile');
    }

    public function testUnverifiedUserCanOpenResendPageAndCsrfIsRequired(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(verified: false));

        $client->request('GET', '/verify/resend');
        self::assertResponseIsSuccessful();
        $client->catchExceptions(false);

        $this->expectException(AccessDeniedException::class);

        $client->request('POST', '/verify/resend', ['_token' => 'bad-token']);
    }
}
