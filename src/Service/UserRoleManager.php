<?php

namespace App\Service;

use App\Entity\AdminRoleAudit;
use App\Entity\User;
use App\Exception\UserRoleManagementException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UserRoleManager
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function grantAdminFromWeb(User $target, User $actor): bool
    {
        $this->assertWebActor($actor);
        $this->assertWebTargetCanBeManaged($target);

        if ($target === $actor) {
            throw new UserRoleManagementException('Vous ne pouvez pas vous attribuer vous-même le rôle administrateur.');
        }

        if (!$target->isVerified()) {
            throw new UserRoleManagementException('L’adresse e-mail de cet utilisateur doit être vérifiée avant toute promotion.');
        }

        return $this->grantRole($target, self::ROLE_ADMIN, $actor, AdminRoleAudit::SOURCE_WEB);
    }

    public function revokeAdminFromWeb(User $target, User $actor): bool
    {
        $this->assertWebActor($actor);
        $this->assertWebTargetCanBeManaged($target);

        if (!in_array(self::ROLE_ADMIN, $target->getRoles(), true)) {
            return false;
        }

        $target->setRoles(array_values(array_filter(
            $target->getRoles(),
            static fn (string $role): bool => $role !== self::ROLE_ADMIN,
        )));
        $this->persistAudit($target, $actor, AdminRoleAudit::ACTION_REVOKE, self::ROLE_ADMIN, AdminRoleAudit::SOURCE_WEB);

        return true;
    }

    public function grantFromBootstrap(User $target, string $role): bool
    {
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true)) {
            throw new UserRoleManagementException('Seuls ROLE_ADMIN et ROLE_SUPER_ADMIN peuvent être amorcés.');
        }

        if (!$target->isVerified()) {
            throw new UserRoleManagementException('Un utilisateur non vérifié ne peut pas recevoir un accès administrateur.');
        }

        return $this->grantRole($target, $role, null, AdminRoleAudit::SOURCE_BOOTSTRAP);
    }

    public function grantAdminFromCli(User $target): bool
    {
        if ($target->isSuperAdmin()) {
            throw new UserRoleManagementException('Un super-administrateur dispose déjà des droits administratifs grâce à la hiérarchie des rôles.');
        }

        if (!$target->isVerified()) {
            throw new UserRoleManagementException('Un utilisateur non vérifié ne peut pas recevoir le rôle administrateur.');
        }

        if ($target->isBanned()) {
            throw new UserRoleManagementException('Un utilisateur banni ne peut pas recevoir le rôle administrateur.');
        }

        return $this->grantRole($target, self::ROLE_ADMIN, null, AdminRoleAudit::SOURCE_CLI);
    }

    public function assertCanDeleteUser(User $target): void
    {
        if (!$target->isSuperAdmin()) {
            return;
        }

        $superAdminCount = 0;
        foreach ($this->userRepository->findAll() as $user) {
            if ($user->isSuperAdmin()) {
                ++$superAdminCount;
            }
        }

        if ($superAdminCount <= 1) {
            throw new UserRoleManagementException('Le dernier super-administrateur ne peut pas être supprimé ou privé de son accès.');
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    private function assertWebActor(User $actor): void
    {
        if (!$actor->isSuperAdmin() || !$actor->isVerified()) {
            throw new UserRoleManagementException('Seul un super-administrateur vérifié peut gérer les rôles.');
        }
    }

    private function assertWebTargetCanBeManaged(User $target): void
    {
        if ($target->isSuperAdmin()) {
            throw new UserRoleManagementException('Les rôles d’un super-administrateur ne sont pas modifiables depuis l’interface web.');
        }
    }

    private function grantRole(User $target, string $role, ?User $actor, string $source): bool
    {
        if (in_array($role, $target->getRoles(), true)) {
            return false;
        }

        $roles = $target->getRoles();
        $roles[] = $role;
        $target->setRoles($roles);
        $this->persistAudit($target, $actor, AdminRoleAudit::ACTION_GRANT, $role, $source);

        return true;
    }

    private function persistAudit(User $target, ?User $actor, string $action, string $role, string $source): void
    {
        $audit = (new AdminRoleAudit())
            ->setActor($actor)
            ->setTargetUser($target)
            ->setAction($action)
            ->setRole($role)
            ->setSource($source);

        $this->entityManager->persist($audit);
    }
}
