<?php

namespace App\Tests\Functional;

final class AdminUserControllerTest extends FunctionalTestCase
{
    public function testAnonymousVisitorIsRedirectedFromUserList(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/users');

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserIsRejectedFromUserList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/admin/users');

        self::assertResponseRedirects('/');
    }

    public function testUnverifiedAdminIsRejectedFromUserList(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUnverifiedAdmin());

        $client->request('GET', '/admin/users');

        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenUserListAndProfile(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $user = $this->createUser();
        $client->loginUser($admin);

        $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $user->getEmail());

        $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $user->getEmail());
    }

    public function testBanRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $user = $this->createUser();
        $client->loginUser($admin);

        $client->request('POST', sprintf('/admin/users/%d/ban', $user->getId()), ['_token' => 'bad-token']);

        self::assertResponseRedirects('/');
        $user = $this->refresh($user);
        self::assertFalse($user->isBanned());
    }

    public function testVerifiedAdminCanBanAndUnbanRegularUser(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $user = $this->createUser();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/users/%d/ban', $user->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/users/%d/ban', $user->getId())),
            'reason' => 'Test fonctionnel de bannissement.',
        ]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $user->getId()));
        $user = $this->refresh($user);
        self::assertTrue($user->isBanned());

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/users/%d/unban', $user->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/users/%d/unban', $user->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $user->getId()));
        $user = $this->refresh($user);
        self::assertFalse($user->isBanned());
    }

    public function testAdminCannotBanAnotherAdminFromThisInterface(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $targetAdmin = $this->createVerifiedAdmin();
        $client->loginUser($admin);
        $crawler = $client->request('GET', sprintf('/admin/users/%d', $targetAdmin->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/users/%d/ban', $targetAdmin->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/users/%d/ban', $targetAdmin->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $targetAdmin->getId()));
        $targetAdmin = $this->refresh($targetAdmin);
        self::assertFalse($targetAdmin->isBanned());
    }
}
