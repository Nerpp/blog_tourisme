<?php

namespace App\Tests\Functional;

use App\Entity\AdminRoleAudit;
use App\Repository\AdminRoleAuditRepository;

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
        $client->request('GET', sprintf('/admin/users/%d', $targetAdmin->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(sprintf('form[action="/admin/users/%d/ban"]', $targetAdmin->getId()));

        $client->request('POST', sprintf('/admin/users/%d/ban', $targetAdmin->getId()), [
            '_token' => $this->csrfTokenForClient($client, 'admin_user_ban_'.$targetAdmin->getId()),
        ]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $targetAdmin->getId()));
        $targetAdmin = $this->refresh($targetAdmin);
        self::assertFalse($targetAdmin->isBanned());
    }

    public function testAnonymousVisitorCannotManageRoles(): void
    {
        $client = static::createClient();
        $target = $this->createUser();

        $client->request('POST', sprintf('/admin/users/%d/roles/admin/grant', $target->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testRegularUserReceivesForbiddenOnRoleManagementUrl(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $target = $this->createUser();

        $client->request('POST', sprintf('/admin/users/%d/roles/admin/grant', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testClassicAdminReceivesForbiddenOnRoleManagementUrl(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $target = $this->createUser();

        $client->request('POST', sprintf('/admin/users/%d/roles/admin/grant', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminCanPromoteVerifiedUserAndAuditIsCreated(): void
    {
        $client = static::createClient();
        $superAdmin = $this->createVerifiedSuperAdmin();
        $target = $this->createUser();
        $client->loginUser($superAdmin);
        $crawler = $client->request('GET', sprintf('/admin/users/%d', $target->getId()));

        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/users/%d/roles/admin/grant', $target->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/users/%d/roles/admin/grant', $target->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $target->getId()));
        $target = $this->refresh($target);
        self::assertContains('ROLE_ADMIN', $target->getRoles());
        $audits = $this->auditRepository()->findRecentForUser($target);
        self::assertCount(1, $audits);
        self::assertSame(AdminRoleAudit::ACTION_GRANT, $audits[0]->getAction());
        self::assertSame($superAdmin->getId(), $audits[0]->getActor()?->getId());
    }

    public function testSuperAdminCanRevokeAdmin(): void
    {
        $client = static::createClient();
        $superAdmin = $this->createVerifiedSuperAdmin();
        $target = $this->createVerifiedAdmin();
        $client->loginUser($superAdmin);
        $crawler = $client->request('GET', sprintf('/admin/users/%d', $target->getId()));

        $client->request('POST', sprintf('/admin/users/%d/roles/admin/revoke', $target->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/users/%d/roles/admin/revoke', $target->getId())),
        ]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $target->getId()));
        $target = $this->refresh($target);
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());
        self::assertSame(AdminRoleAudit::ACTION_REVOKE, $this->auditRepository()->findRecentForUser($target)[0]->getAction());
    }

    public function testRoleChangeRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedSuperAdmin());
        $target = $this->createUser();

        $client->request('POST', sprintf('/admin/users/%d/roles/admin/grant', $target->getId()), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        $target = $this->refresh($target);
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());
    }

    public function testInterfaceNeverOffersSuperAdminPromotion(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedSuperAdmin());
        $target = $this->createUser();

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $target->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action*="roles/super"]');
        self::assertSelectorTextNotContains('body', 'Promouvoir super-administrateur');
    }

    public function testUnverifiedUserCannotBecomeAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedSuperAdmin());
        $target = $this->createUser(['ROLE_USER'], false);
        $targetId = $target->getId();
        self::assertNotNull($targetId);
        $token = $this->csrfTokenForClient($client, 'admin_user_role_grant_'.$targetId);

        $client->request('POST', sprintf('/admin/users/%d/roles/admin/grant', $targetId), ['_token' => $token]);

        self::assertResponseRedirects(sprintf('/admin/users/%d', $targetId));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'doit être vérifiée');
        $target = $this->refresh($target);
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());
    }

    public function testRoleChangeRoutesRejectGetRequests(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedSuperAdmin());
        $target = $this->createUser();

        $client->request('GET', sprintf('/admin/users/%d/roles/admin/grant', $target->getId()));
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', sprintf('/admin/users/%d/roles/admin/revoke', $target->getId()));
        self::assertResponseStatusCodeSame(405);
    }

    private function auditRepository(): AdminRoleAuditRepository
    {
        $repository = static::getContainer()->get(AdminRoleAuditRepository::class);
        self::assertInstanceOf(AdminRoleAuditRepository::class, $repository);

        return $repository;
    }
}
