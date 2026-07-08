<?php

namespace App\Repository;

use App\Entity\AdminRoleAudit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminRoleAudit> */
final class AdminRoleAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminRoleAudit::class);
    }

    /** @return list<AdminRoleAudit> */
    public function findRecentForUser(User $user, int $limit = 20): array
    {
        /** @var list<AdminRoleAudit> $audits */
        $audits = $this->findBy(['targetUser' => $user], ['createdAt' => 'DESC'], $limit);

        return $audits;
    }
}
