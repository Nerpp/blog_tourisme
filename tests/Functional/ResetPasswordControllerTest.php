<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

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
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Si un compte existe avec cette adresse email');
    }

    public function testCheckEmailPageShowsGenericMessage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/reset-password/check-email');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Si un compte existe avec cette adresse email');
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
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Si un compte existe avec cette adresse email');
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

    public function testInvalidNewPasswordDoesNotChangePasswordOrConsumeToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $originalPassword = $user->getPassword();
        $token = $this->resetPasswordHelper()->generateResetToken($user)->getToken();
        $crawler = $this->openResetForm($client, $token);

        $client->request('POST', '/reset-password/reset', [
            'change_password_form' => [
                'plainPassword' => [
                    'first' => 'court',
                    'second' => 'court',
                ],
                '_token' => $this->inputValue($crawler, 'input[name="change_password_form[_token]"]'),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame($originalPassword, $storedUser->getPassword());
        $tokenUser = $this->resetPasswordHelper()->validateTokenAndFetchUser($token);
        self::assertInstanceOf(User::class, $tokenUser);
        self::assertSame($storedUser->getId(), $tokenUser->getId());
    }

    public function testValidResetTokenChangesPasswordAndCannotBeReused(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $newPassword = 'Nouvelle phrase secrète 2026 !';
        $token = $this->resetPasswordHelper()->generateResetToken($user)->getToken();
        $crawler = $this->openResetForm($client, $token);

        $client->request('POST', '/reset-password/reset', [
            'change_password_form' => [
                'plainPassword' => [
                    'first' => $newPassword,
                    'second' => $newPassword,
                ],
                '_token' => $this->inputValue($crawler, 'input[name="change_password_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/login');
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertTrue($this->passwordHasher()->isPasswordValid($storedUser, $newPassword));

        $client->request('GET', '/reset-password/reset/'.$token);
        self::assertResponseRedirects('/reset-password/reset');
        $client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    private function openResetForm(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token): \Symfony\Component\DomCrawler\Crawler
    {
        $client->request('GET', '/reset-password/reset/'.$token);
        self::assertResponseRedirects('/reset-password/reset');

        return $client->followRedirect();
    }

    private function resetPasswordHelper(): ResetPasswordHelperInterface
    {
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        self::assertInstanceOf(ResetPasswordHelperInterface::class, $helper);

        return $helper;
    }

    private function passwordHasher(): UserPasswordHasherInterface
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }
}
