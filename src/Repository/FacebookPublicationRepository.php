<?php

namespace App\Repository;

use App\Entity\FacebookPublication;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FacebookPublication> */
class FacebookPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FacebookPublication::class);
    }

    public function findOneBySource(
        FacebookPublicationSourceType $sourceType,
        int $sourceId,
    ): ?FacebookPublication {
        return $this->findOneBy([
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
        ]);
    }

    public function findOneForUpdate(int $id): ?FacebookPublication
    {
        $publication = $this->createQueryBuilder('publication')
            ->andWhere('publication.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $publication instanceof FacebookPublication ? $publication : null;
    }

    /** @return list<FacebookPublication> */
    public function findPendingForDispatch(DateTimeImmutable $pendingCutoff, int $limit = 50): array
    {
        /** @var list<FacebookPublication> $publications */
        $publications = $this->createQueryBuilder('publication')
            ->andWhere('publication.status = :status')
            ->andWhere(
                '(publication.lastDispatchedAt IS NULL AND publication.createdAt <= :pendingCutoff)'
                .' OR publication.lastDispatchedAt <= :pendingCutoff',
            )
            ->setParameter('status', FacebookPublicationStatus::Pending)
            ->setParameter('pendingCutoff', $pendingCutoff)
            ->orderBy('publication.createdAt', 'ASC')
            ->addOrderBy('publication.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $publications;
    }
}
