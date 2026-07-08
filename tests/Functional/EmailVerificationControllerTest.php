<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerificationControllerTest extends FunctionalTestCase
{
    public function testInvalidVerificationLinkRedirectsToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/verify/email?id=999999&hash=invalid');

        self::assertResponseRedirects('/login');
    }

    public function testValidVerificationLinkVerifiesOnlyItsUser(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: false);
        $otherUser = $this->createUser(verified: false);

        $client->request('GET', $this->verificationUrl($user));

        self::assertResponseRedirects('/login');
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $user->getId());
        $storedOtherUser = $this->entityManager()->find(User::class, $otherUser->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(User::class, $storedOtherUser);
        self::assertTrue($storedUser->isVerified());
        self::assertFalse($storedOtherUser->isVerified());
    }

    public function testVerificationLinkCannotBeReaddressedToAnotherUser(): void
    {
        $client = static::createClient();
        $signedUser = $this->createUser(verified: false);
        $targetUser = $this->createUser(verified: false);
        $alteredUrl = preg_replace(
            '/([?&]id=)\d+/',
            '${1}'.(string) $targetUser->getId(),
            $this->verificationUrl($signedUser),
            1,
        );
        self::assertIsString($alteredUrl);

        $client->request('GET', $alteredUrl);

        self::assertResponseRedirects('/login');
        $this->entityManager()->clear();
        $storedSignedUser = $this->entityManager()->find(User::class, $signedUser->getId());
        $storedTargetUser = $this->entityManager()->find(User::class, $targetUser->getId());
        self::assertInstanceOf(User::class, $storedSignedUser);
        self::assertInstanceOf(User::class, $storedTargetUser);
        self::assertFalse($storedSignedUser->isVerified());
        self::assertFalse($storedTargetUser->isVerified());
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

    public function testUnverifiedUserCanRequestVerificationEmailResend(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(verified: false));

        $crawler = $client->request('GET', '/verify/resend');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/verify/resend', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
        ]);

        self::assertResponseRedirects('/profile');
    }

    private function verificationUrl(User $user): string
    {
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);
        self::assertInstanceOf(VerifyEmailHelperInterface::class, $helper);

        return $helper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => $user->getId()],
        )->getSignedUrl();
    }
}
