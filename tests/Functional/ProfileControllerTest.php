<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ProfileControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromPrivateProfile(): void
    {
        $client = static::createClient();

        $client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
    }

    public function testLoggedInUserCanOpenPrivateProfileWithAvatarFallback(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $displayName = 'Nom fonctionnel '.$this->uniqueToken('profile');
        $client->loginUser($user);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $user->getEmail());
        self::assertSelectorTextContains('body', 'Initiale par défaut');
    }

    public function testPublicProfileDisplaysAvatarPathWhenPresent(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/test-avatar.png');
        $this->persistAndFlush($user);

        $client->request('GET', sprintf('/users/%d', $user->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $user->getDisplayName());
        self::assertStringContainsString('/uploads/avatars/test-avatar.png', $client->getResponse()->getContent() ?: '');
    }

    public function testPrivateProfileDisplaysAvatarPathWhenPresent(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/private-avatar.webp');
        $this->persistAndFlush($user);
        $client->loginUser($user);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/avatars/private-avatar.webp', $client->getResponse()->getContent() ?: '');
    }

    public function testConnectedHeaderDisplaysAvatarWhenPresent(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/header-avatar.webp');
        $this->persistAndFlush($user);
        $client->loginUser($user);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/avatars/header-avatar.webp', $client->getResponse()->getContent() ?: '');
    }

    public function testProfileDisplayNameCanBeUpdatedWithValidCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $displayName = 'Nom fonctionnel '.$this->uniqueToken('profile');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => $displayName,
                'receivePublicationEmails' => '1',
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertSame($displayName, $user->getDisplayName());
    }

    public function testProfileDisplayNameAtCurrentLengthBoundaryIsPersisted(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $displayName = substr('Nom '.$this->uniqueToken('profile'), 0, 120);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => $displayName,
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertSame($displayName, $user->getDisplayName());
    }

    public function testInvalidProfileDataDoesNotOverwritePersistedValues(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $userId = $user->getId();
        $originalDisplayName = $user->getDisplayName();
        $user->setReceivePublicationEmails(true);
        $this->persistAndFlush($user);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile');

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => str_repeat('Nom invalide ', 20),
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame($originalDisplayName, $storedUser->getDisplayName());
        self::assertTrue($storedUser->isReceivePublicationEmails());
    }

    public function testProfileRejectsPrivilegedFieldsWithoutChangingAccountState(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $userId = $user->getId();
        $originalDisplayName = $user->getDisplayName();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile');

        $client->request('POST', '/profile', [
            'profile_form' => [
                'displayName' => 'Nom injecté '.$this->uniqueToken('profile'),
                'roles' => ['ROLE_ADMIN'],
                'isVerified' => '1',
                'isBanned' => '1',
                '_token' => $this->inputValue($crawler, 'input[name="profile_form[_token]"]'),
            ],
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $storedUser = $this->entityManager()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame($originalDisplayName, $storedUser->getDisplayName());
        self::assertSame(['ROLE_USER'], $storedUser->getRoles());
        self::assertTrue($storedUser->isVerified());
        self::assertFalse($storedUser->isBanned());
    }

    public function testDeleteAvatarRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/test-avatar.png');
        $this->persistAndFlush($user);
        $client->loginUser($user);
        $client->catchExceptions(false);

        $this->expectException(AccessDeniedException::class);

        $client->request('POST', '/profile', [
            '_profile_action' => 'delete_avatar',
            '_token' => 'bad-token',
        ]);
    }

    public function testDeleteAvatarWithValidCsrfClearsAvatarPath(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $user->setAvatarPath('/uploads/avatars/test-avatar.png');
        $this->persistAndFlush($user);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/profile', [
            '_profile_action' => 'delete_avatar',
            '_token' => $this->inputValue($crawler, 'form#profile-avatar-delete-form input[name="_token"]'),
        ]);

        self::assertResponseRedirects('/profile');
        $user = $this->refresh($user);
        self::assertNull($user->getAvatarPath());
    }
}
