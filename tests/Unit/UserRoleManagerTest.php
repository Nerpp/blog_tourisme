<?php

namespace App\Tests\Unit;

use App\Entity\AdminRoleAudit;
use App\Entity\User;
use App\Exception\UserRoleManagementException;
use App\Repository\UserRepository;
use App\Service\UserRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class UserRoleManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UserRepository&MockObject $userRepository;
    private UserRoleManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->manager = new UserRoleManager($this->entityManager, $this->userRepository);
    }

    public function testGrantAdminAddsRoleWithoutDuplicateAndCreatesAudit(): void
    {
        $actor = $this->user(['ROLE_SUPER_ADMIN']);
        $target = $this->user(['ROLE_USER']);
        $audit = null;
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$audit): void {
                $audit = $entity;
            });

        self::assertTrue($this->manager->grantAdminFromWeb($target, $actor));
        self::assertContains('ROLE_ADMIN', $target->getRoles());
        self::assertInstanceOf(AdminRoleAudit::class, $audit);
        self::assertSame(AdminRoleAudit::ACTION_GRANT, $audit->getAction());
        self::assertSame(UserRoleManager::ROLE_ADMIN, $audit->getRole());
        self::assertSame(AdminRoleAudit::SOURCE_WEB, $audit->getSource());
        self::assertSame($actor, $audit->getActor());
        self::assertSame($target, $audit->getTargetUser());
    }

    public function testGrantAdminIsIdempotent(): void
    {
        $target = $this->user(['ROLE_ADMIN']);
        $this->entityManager->expects(self::never())->method('persist');

        self::assertFalse($this->manager->grantAdminFromWeb($target, $this->user(['ROLE_SUPER_ADMIN'])));
        self::assertSame(1, count(array_filter(
            $target->getRoles(),
            static fn (string $role): bool => $role === UserRoleManager::ROLE_ADMIN,
        )));
    }

    public function testRevokeAdminKeepsOtherRolesAndCreatesAudit(): void
    {
        $actor = $this->user(['ROLE_SUPER_ADMIN']);
        $target = $this->user(['ROLE_ADMIN', 'ROLE_EDITOR']);
        $audit = null;
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$audit): void {
                $audit = $entity;
            });

        self::assertTrue($this->manager->revokeAdminFromWeb($target, $actor));
        self::assertNotContains('ROLE_ADMIN', $target->getRoles());
        self::assertContains('ROLE_EDITOR', $target->getRoles());
        self::assertInstanceOf(AdminRoleAudit::class, $audit);
        self::assertSame(AdminRoleAudit::ACTION_REVOKE, $audit->getAction());
        self::assertSame(AdminRoleAudit::SOURCE_WEB, $audit->getSource());
    }

    public function testUnverifiedUserCannotBePromoted(): void
    {
        $target = $this->user(['ROLE_USER'], false);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(UserRoleManagementException::class);
        $this->expectExceptionMessage('vérifiée');
        $this->manager->grantAdminFromWeb($target, $this->user(['ROLE_SUPER_ADMIN']));
    }

    public function testLastSuperAdminCannotBeDeleted(): void
    {
        $lastSuperAdmin = $this->user(['ROLE_SUPER_ADMIN']);
        $this->userRepository->method('findAll')->willReturn([$lastSuperAdmin, $this->user(['ROLE_USER'])]);

        $this->expectException(UserRoleManagementException::class);
        $this->expectExceptionMessage('dernier super-administrateur');
        $this->manager->assertCanDeleteUser($lastSuperAdmin);
    }

    public function testSuperAdminRolesCannotBeModifiedFromWebMethods(): void
    {
        $target = $this->user(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(UserRoleManagementException::class);
        $this->expectExceptionMessage('ne sont pas modifiables');
        $this->manager->revokeAdminFromWeb($target, $this->user(['ROLE_SUPER_ADMIN']));
    }

    public function testOnlyBootstrapMethodCanGrantSuperAdmin(): void
    {
        $target = $this->user(['ROLE_USER']);
        $audit = null;
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$audit): void {
                $audit = $entity;
            });

        self::assertTrue($this->manager->grantFromBootstrap($target, UserRoleManager::ROLE_SUPER_ADMIN));
        self::assertTrue($target->isSuperAdmin());
        self::assertInstanceOf(AdminRoleAudit::class, $audit);
        self::assertNull($audit->getActor());
        self::assertSame(AdminRoleAudit::SOURCE_BOOTSTRAP, $audit->getSource());
    }

    public function testGrantAdminFromCliKeepsExistingRolesAndCreatesCliAudit(): void
    {
        $target = $this->user(['ROLE_EDITOR']);
        $audit = null;
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$audit): void {
                $audit = $entity;
            });

        self::assertTrue($this->manager->grantAdminFromCli($target));
        self::assertContains(UserRoleManager::ROLE_ADMIN, $target->getRoles());
        self::assertContains('ROLE_EDITOR', $target->getRoles());
        self::assertInstanceOf(AdminRoleAudit::class, $audit);
        self::assertSame(AdminRoleAudit::ACTION_GRANT, $audit->getAction());
        self::assertSame(UserRoleManager::ROLE_ADMIN, $audit->getRole());
        self::assertSame(AdminRoleAudit::SOURCE_CLI, $audit->getSource());
        self::assertNull($audit->getActor());
        self::assertSame($target, $audit->getTargetUser());
    }

    public function testGrantAdminFromCliIsIdempotentWithoutNewAudit(): void
    {
        $target = $this->user([UserRoleManager::ROLE_ADMIN, 'ROLE_EDITOR']);
        $this->entityManager->expects(self::never())->method('persist');

        self::assertFalse($this->manager->grantAdminFromCli($target));
        self::assertSame(1, count(array_filter(
            $target->getRoles(),
            static fn (string $role): bool => $role === UserRoleManager::ROLE_ADMIN,
        )));
        self::assertContains('ROLE_EDITOR', $target->getRoles());
    }

    public function testGrantAdminFromCliRejectsUnverifiedUser(): void
    {
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(UserRoleManagementException::class);
        $this->expectExceptionMessage('non vérifié');
        $this->manager->grantAdminFromCli($this->user([], false));
    }

    public function testGrantAdminFromCliRejectsBannedUser(): void
    {
        $target = $this->user([])->setIsBanned(true);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(UserRoleManagementException::class);
        $this->expectExceptionMessage('banni');
        $this->manager->grantAdminFromCli($target);
    }

    public function testGrantAdminFromCliRejectsSuperAdmin(): void
    {
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(UserRoleManagementException::class);
        $this->expectExceptionMessage('hiérarchie des rôles');
        $this->manager->grantAdminFromCli($this->user([UserRoleManager::ROLE_SUPER_ADMIN]));
    }

    /** @param list<string> $roles */
    private function user(array $roles, bool $verified = true): User
    {
        return (new User())
            ->setEmail(bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Utilisateur test '.bin2hex(random_bytes(3)))
            ->setPassword('test-password')
            ->setRoles($roles)
            ->setIsVerified($verified);
    }
}
