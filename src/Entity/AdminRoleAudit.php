<?php

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use App\Repository\AdminRoleAuditRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminRoleAuditRepository::class)]
#[ORM\Index(name: 'idx_admin_role_audit_target_created', fields: ['targetUser', 'createdAt'])]
#[ORM\Index(name: 'idx_admin_role_audit_created_at', fields: ['createdAt'])]
#[ORM\HasLifecycleCallbacks]
class AdminRoleAudit
{
    use CreatedAtTrait;

    public const ACTION_GRANT = 'grant';
    public const ACTION_REVOKE = 'revoke';
    public const SOURCE_WEB = 'web';
    public const SOURCE_BOOTSTRAP = 'bootstrap_command';
    public const SOURCE_CLI = 'cli_command';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $targetUser = null;

    #[ORM\Column(length: 16)]
    private string $action = '';

    #[ORM\Column(length: 32)]
    private string $role = '';

    #[ORM\Column(length: 32)]
    private string $source = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function setTargetUser(User $targetUser): static
    {
        $this->targetUser = $targetUser;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        if (!in_array($action, [self::ACTION_GRANT, self::ACTION_REVOKE], true)) {
            throw new \InvalidArgumentException('Action d’audit de rôle invalide.');
        }

        $this->action = $action;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!in_array($role, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true)) {
            throw new \InvalidArgumentException('Rôle d’audit invalide.');
        }

        $this->role = $role;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        if (!in_array($source, [self::SOURCE_WEB, self::SOURCE_BOOTSTRAP, self::SOURCE_CLI], true)) {
            throw new \InvalidArgumentException('Source d’audit de rôle invalide.');
        }

        $this->source = $source;

        return $this;
    }
}
