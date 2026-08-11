<?php

namespace App\Repository;

use App\Entity\InstagramPublication;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InstagramPublication> */
class InstagramPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramPublication::class);
    }

    public function findOneBySource(InstagramPublicationSourceType $sourceType, int $sourceId): ?InstagramPublication
    {
        return $this->findOneBy([
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
        ]);
    }

    public function existsForSource(InstagramPublicationSourceType $sourceType, int $sourceId): bool
    {
        return $this->findOneBySource($sourceType, $sourceId) instanceof InstagramPublication;
    }

    public function findOneForUpdate(int $id): ?InstagramPublication
    {
        $publication = $this->createQueryBuilder('publication')
            ->andWhere('publication.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $publication instanceof InstagramPublication ? $publication : null;
    }

    /** @return list<InstagramPublication> */
    public function findPendingForDispatch(DateTimeImmutable $notDispatchedSince, int $limit = 50): array
    {
        /** @var list<InstagramPublication> $publications */
        $publications = $this->createQueryBuilder('publication')
            ->andWhere('publication.status = :status')
            ->andWhere('publication.lastDispatchedAt IS NULL OR publication.lastDispatchedAt <= :notDispatchedSince')
            ->setParameter('status', InstagramPublicationStatus::Pending)
            ->setParameter('notDispatchedSince', $notDispatchedSince)
            ->orderBy('publication.createdAt', 'ASC')
            ->addOrderBy('publication.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $publications;
    }

    /** @return list<InstagramPublication> */
    public function findRecoverableForDispatch(
        DateTimeImmutable $pendingCutoff,
        DateTimeImmutable $processingCutoff,
        int $limit = 50,
    ): array {
        /** @var list<InstagramPublication> $publications */
        $publications = $this->createQueryBuilder('publication')
            ->andWhere(
                '(publication.status = :pendingStatus AND (publication.lastDispatchedAt IS NULL OR publication.lastDispatchedAt <= :pendingCutoff))'
                .' OR (publication.status = :processingStatus AND (publication.lastAttemptAt IS NULL OR publication.lastAttemptAt <= :processingCutoff))',
            )
            ->setParameter('pendingStatus', InstagramPublicationStatus::Pending)
            ->setParameter('pendingCutoff', $pendingCutoff)
            ->setParameter('processingStatus', InstagramPublicationStatus::Processing)
            ->setParameter('processingCutoff', $processingCutoff)
            ->orderBy('publication.createdAt', 'ASC')
            ->addOrderBy('publication.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $publications;
    }
}
