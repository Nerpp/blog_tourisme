<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerificationControllerTest extends FunctionalTestCase
{
    public function testSec01LocalRecoveryRejectsPreVerificationPasswordAndSession(): void
    {
        $attacker = static::createClient();
        $legacyPassword = 'Phrase connue attaquant 2026 9!';
        $victimPassword = 'Phrase choisie victime 2026 8!';
        $user = $this->legacyUnverifiedUser($legacyPassword);
        $verificationUrl = $this->verificationUrl($user);

        $this->attemptPasswordLogin($attacker, $user, $legacyPassword);
        self::assertResponseRedirects('/login');
        $attacker->request('GET', '/profile');
        self::assertResponseRedirects('/login');

        // Simule la session créée par l’ancienne version avant le déploiement du correctif.
        $attacker->loginUser($user);
        self::assertNull($attacker->getCookieJar()->get('REMEMBERME'));

        $victim = $this->independentClient($attacker);
        $this->submitInitialPassword($victim, $verificationUrl, $victimPassword);
        $this->assertClientRedirects($victim, '/login');

        $storedUser = $this->storedUser($user);
        self::assertTrue($storedUser->isVerified());
        self::assertNull($storedUser->getEmailVerificationTokenHash());
        self::assertSame(['ROLE_USER'], $storedUser->getRoles());
        self::assertFalse($this->passwordHasher()->isPasswordValid($storedUser, $legacyPassword));
        self::assertTrue($this->passwordHasher()->isPasswordValid($storedUser, $victimPassword));

        $attacker->request('GET', '/profile');
        self::assertResponseRedirects('/login');

        $oldPasswordClient = $this->independentClient($attacker);
        $this->attemptPasswordLogin($oldPasswordClient, $storedUser, $legacyPassword);
        $this->assertClientRedirects($oldPasswordClient, '/login');

        $victimLogin = $this->independentClient($attacker);
        $this->attemptPasswordLogin($victimLogin, $storedUser, $victimPassword);
        $this->assertClientRedirects($victimLogin, '/');
    }

    public function testPasswordHashRotationInvalidatesLegacySerializedSession(): void
    {
        $client = static::createClient();
        $user = $this->legacyUnverifiedUser('Phrase héritée session 2026 9!');
        $client->loginUser($user);

        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $user->setPassword($this->passwordHasher()->hashPassword($user, bin2hex(random_bytes(48))));
        $this->entityManager()->flush();

        $client->request('GET', '/profile');
        self::assertResponseRedirects('/login');
    }

    public function testInvalidVerificationLinkRedirectsToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/verify/email?id=999999&signature=invalid');

        self::assertResponseRedirects('/login');
    }

    public function testValidLinkRequiresPasswordBeforeVerifyingOnlyItsUser(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: false);
        $otherUser = $this->createUser(verified: false);
        $verificationUrl = $this->verificationUrl($user);

        $crawler = $client->request('GET', $verificationUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Créer votre mot de passe');
        self::assertFalse($this->storedUser($user)->isVerified());

        $client->submit($crawler->selectButton('Activer mon compte')->form([
            'initial_password_form[plainPassword][first]' => 'Phrase valide activation 2026 7!',
            'initial_password_form[plainPassword][second]' => 'Phrase valide activation 2026 7!',
        ]));

        self::assertResponseRedirects('/login');
        self::assertTrue($this->storedUser($user)->isVerified());
        self::assertFalse($this->storedUser($otherUser)->isVerified());
    }

    public function testVerificationLinkCannotBeReaddressedToAnotherUser(): void
    {
        $client = static::createClient();
        $signedUser = $this->createUser(verified: false);
        $targetUser = $this->createUser(verified: false);
        $alteredUrl = preg_replace(
            '/([?&]id=)\d+/',
            '$1'.(string) $targetUser->getId(),
            $this->verificationUrl($signedUser),
            1,
        );
        self::assertIsString($alteredUrl);

        $client->request('GET', $alteredUrl);

        self::assertResponseRedirects('/login');
        self::assertFalse($this->storedUser($signedUser)->isVerified());
        self::assertFalse($this->storedUser($targetUser)->isVerified());
    }

    public function testVerificationLinkRejectsTamperedSignatureAndEmailBinding(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: false);
        $tamperedUrl = preg_replace(
            '/([?&]signature=)[^&]+/',
            '$1tampered',
            $this->verificationUrl($user),
            1,
        );
        self::assertIsString($tamperedUrl);

        $client->request('GET', $tamperedUrl);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->storedUser($user)->isVerified());

        $wrongEmailUrl = $this->verificationUrl($user, signedEmail: 'another-address@example.test');
        $client->request('GET', $wrongEmailUrl);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->storedUser($user)->isVerified());
    }

    public function testVerificationLinkRejectsExpiredSignatureAndWrongPurpose(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: false);
        $expiredUrl = $this->resignUrl($this->verificationUrl($user), [
            'expires' => (string) (time() - 1),
        ]);
        $this->storeVerificationSignature($user, $expiredUrl);

        $client->request('GET', $expiredUrl);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->storedUser($user)->isVerified());

        $wrongPurposeUrl = $this->verificationUrl($user, purpose: 'password-reset');
        $client->request('GET', $wrongPurposeUrl);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->storedUser($user)->isVerified());
    }

    public function testInvalidCsrfAndWeakPasswordDoNotConsumeVerificationLink(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: false);
        $verificationUrl = $this->verificationUrl($user);
        $tokenHash = $user->getEmailVerificationTokenHash();

        $client->request('POST', $verificationUrl, [
            'initial_password_form' => [
                'plainPassword' => [
                    'first' => 'Phrase valide CSRF 2026 9!',
                    'second' => 'Phrase valide CSRF 2026 9!',
                ],
                '_token' => 'invalid-csrf-token',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $storedUser = $this->storedUser($user);
        self::assertFalse($storedUser->isVerified());
        self::assertSame($tokenHash, $storedUser->getEmailVerificationTokenHash());

        $crawler = $client->request('GET', $verificationUrl);
        $client->submit($crawler->selectButton('Activer mon compte')->form([
            'initial_password_form[plainPassword][first]' => '12345678',
            'initial_password_form[plainPassword][second]' => '12345678',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Le mot de passe doit contenir au moins 12 caractères.');
        $storedUser = $this->storedUser($user);
        self::assertFalse($storedUser->isVerified());
        self::assertSame($tokenHash, $storedUser->getEmailVerificationTokenHash());
    }

    public function testOnlyFirstOfTwoSuccessiveClientsCanConsumeLink(): void
    {
        $firstClient = static::createClient();
        $secondClient = $this->independentClient($firstClient);
        $user = $this->createUser(verified: false);
        $verificationUrl = $this->verificationUrl($user);
        $firstForm = $firstClient->request('GET', $verificationUrl);
        $secondForm = $secondClient->request('GET', $verificationUrl);
        self::assertResponseIsSuccessful();

        $firstClient->submit($firstForm->selectButton('Activer mon compte')->form([
            'initial_password_form[plainPassword][first]' => 'Première phrase valide 2026 9!',
            'initial_password_form[plainPassword][second]' => 'Première phrase valide 2026 9!',
        ]));
        self::assertResponseRedirects('/login');

        $secondClient->submit($secondForm->selectButton('Activer mon compte')->form([
            'initial_password_form[plainPassword][first]' => 'Seconde phrase valide 2026 8!',
            'initial_password_form[plainPassword][second]' => 'Seconde phrase valide 2026 8!',
        ]));
        $this->assertClientRedirects($secondClient, '/login');

        $storedUser = $this->storedUser($user);
        self::assertTrue($this->passwordHasher()->isPasswordValid($storedUser, 'Première phrase valide 2026 9!'));
        self::assertFalse($this->passwordHasher()->isPasswordValid($storedUser, 'Seconde phrase valide 2026 8!'));
    }

    public function testSuccessfulActivationInvalidatesResetTokenAndCannotBeReplayed(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: false);
        $resetToken = $this->resetPasswordHelper()->generateResetToken($user)->getToken();
        $verificationUrl = $this->verificationUrl($user);

        $this->submitInitialPassword($client, $verificationUrl, 'Phrase activation jetons 2026 9!');
        self::assertResponseRedirects('/login');

        $client->request('GET', $verificationUrl);
        self::assertResponseRedirects('/login');

        try {
            $this->resetPasswordHelper()->validateTokenAndFetchUser($resetToken);
            self::fail('The reset token should have been invalidated by account activation.');
        } catch (ResetPasswordExceptionInterface) {
            self::addToAssertionCount(1);
        }
    }

    public function testAnonymousVisitorCanOpenResendPage(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/verify/resend');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[name="resend_verification_form[email]"]')->count());
    }

    public function testAlreadyVerifiedUserCanOpenPublicResendPage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(verified: true));

        $crawler = $client->request('GET', '/verify/resend');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[name="resend_verification_form[email]"]')->count());
    }

    private function legacyUnverifiedUser(string $plainPassword): User
    {
        $user = $this->createUser(verified: false);
        $user->setPassword($this->passwordHasher()->hashPassword($user, $plainPassword));
        $this->entityManager()->flush();

        return $user;
    }

    private function verificationUrl(
        User $user,
        ?string $signedEmail = null,
        string $purpose = 'initial-password',
    ): string {
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);
        self::assertInstanceOf(VerifyEmailHelperInterface::class, $helper);

        $url = $helper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            $signedEmail ?? (string) $user->getEmail(),
            [
                'id' => $user->getId(),
                'purpose' => $purpose,
            ],
        )->getSignedUrl();
        $this->storeVerificationSignature($user, $url);

        return $url;
    }

    /** @param array<string, string> $changes */
    private function resignUrl(string $url, array $changes): string
    {
        $parts = parse_url($url);
        self::assertIsArray($parts);
        parse_str(is_string($parts['query'] ?? null) ? $parts['query'] : '', $query);
        unset($query['signature']);
        foreach ($changes as $name => $value) {
            $query[$name] = $value;
        }

        $unsignedUrl = sprintf(
            '%s://%s%s%s?%s',
            is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https',
            is_string($parts['host'] ?? null) ? $parts['host'] : 'estela-exploration.fr',
            isset($parts['port']) ? ':'.(string) $parts['port'] : '',
            is_string($parts['path'] ?? null) ? $parts['path'] : '/verify/email',
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );

        $signer = static::getContainer()->get('symfonycasts.verify_email.uri_signer');
        self::assertInstanceOf(UriSigner::class, $signer);

        return $signer->sign($unsignedUrl);
    }

    private function storeVerificationSignature(User $user, string $url): void
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $parameters);
        $signature = $parameters['signature'] ?? null;
        self::assertIsString($signature);

        $managedUser = $this->entityManager()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managedUser);
        $managedUser->setEmailVerificationTokenHash(hash('sha256', $signature));
        $this->entityManager()->flush();
    }

    private function submitInitialPassword(KernelBrowser $client, string $url, string $password): void
    {
        $crawler = $client->request('GET', $url);
        self::assertTrue($client->getResponse()->isSuccessful());
        $client->submit($crawler->selectButton('Activer mon compte')->form([
            'initial_password_form[plainPassword][first]' => $password,
            'initial_password_form[plainPassword][second]' => $password,
        ]));
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

    private function storedUser(User $user): User
    {
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);

        return $storedUser;
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

    private function independentClient(KernelBrowser $client): KernelBrowser
    {
        return new KernelBrowser($client->getKernel());
    }

    private function assertClientRedirects(KernelBrowser $client, string $expectedPath): void
    {
        $location = $client->getResponse()->headers->get('Location');
        self::assertTrue($client->getResponse()->isRedirection());
        self::assertIsString($location);
        self::assertSame($expectedPath, parse_url($location, PHP_URL_PATH));
    }
}
