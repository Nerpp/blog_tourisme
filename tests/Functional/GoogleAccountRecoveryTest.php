<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\GoogleAuthenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class GoogleAccountRecoveryTest extends FunctionalTestCase
{
    public function testVerifiedGoogleIdentityNeutralizesLegacyPasswordSessionAndTokens(): void
    {
        $attacker = static::createClient();
        $legacyPassword = 'Phrase Google attaquant 2026 9!';
        $user = $this->createUser(verified: false);
        $user
            ->setPassword($this->passwordHasher()->hashPassword($user, $legacyPassword))
            ->setEmailVerificationTokenHash(hash('sha256', 'pending-email-verification'));
        $this->entityManager()->flush();
        $resetToken = $this->resetPasswordHelper()->generateResetToken($user)->getToken();

        $this->attemptPasswordLogin($attacker, $user, $legacyPassword);
        self::assertResponseRedirects('/login');

        // Simule une session ouverte par l’ancienne version avant le déploiement.
        $attacker->loginUser($user);
        $googleIdentity = new GoogleUser([
            'sub' => 'google-sec01-'.$this->uniqueToken('identity'),
            'email' => strtoupper((string) $user->getEmail()),
            'email_verified' => true,
            'name' => 'Victime Google',
        ]);

        $recovered = $this->recoverWithGoogle($googleIdentity);
        self::assertSame($user->getId(), $recovered->getId());
        self::assertTrue($recovered->isVerified());
        self::assertNotNull($recovered->getGoogleId());
        self::assertNull($recovered->getEmailVerificationTokenHash());
        self::assertSame(['ROLE_USER'], $recovered->getRoles());
        self::assertFalse($this->passwordHasher()->isPasswordValid($recovered, $legacyPassword));
        $rotatedPasswordHash = $recovered->getPassword();

        $sameGoogleAccount = $this->recoverWithGoogle($googleIdentity);
        self::assertSame($recovered->getId(), $sameGoogleAccount->getId());
        self::assertSame($rotatedPasswordHash, $sameGoogleAccount->getPassword());

        $attacker->request('GET', '/profile');
        self::assertResponseRedirects('/login');

        $oldPasswordClient = new KernelBrowser($attacker->getKernel());
        $this->attemptPasswordLogin($oldPasswordClient, $sameGoogleAccount, $legacyPassword);
        $this->assertClientRedirects($oldPasswordClient, '/login');

        try {
            $this->resetPasswordHelper()->validateTokenAndFetchUser($resetToken);
            self::fail('Google recovery should invalidate previous reset tokens.');
        } catch (ResetPasswordExceptionInterface) {
            self::addToAssertionCount(1);
        }
    }

    private function recoverWithGoogle(GoogleUser $googleUser): User
    {
        $authenticator = static::getContainer()->get(GoogleAuthenticator::class);
        self::assertInstanceOf(GoogleAuthenticator::class, $authenticator);
        $method = new \ReflectionMethod($authenticator, 'findOrCreateUser');
        $user = $method->invoke($authenticator, $googleUser);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function attemptPasswordLogin(KernelBrowser $client, User $user, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->request('POST', '/login', [
            '_username' => $user->getEmail(),
            '_password' => $password,
            '_csrf_token' => $this->inputValue($crawler, 'input[name="_csrf_token"]'),
        ]);
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);

        return $passwordHasher;
    }

    private function resetPasswordHelper(): ResetPasswordHelperInterface
    {
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $helper);

        return $helper;
    }

    private function assertClientRedirects(KernelBrowser $client, string $expectedPath): void
    {
        $location = $client->getResponse()->headers->get('Location');
        self::assertTrue($client->getResponse()->isRedirection());
        self::assertIsString($location);
        self::assertSame($expectedPath, parse_url($location, PHP_URL_PATH));
    }
}
