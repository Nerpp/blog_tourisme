<?php

namespace App\Tests\Functional;

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
}
