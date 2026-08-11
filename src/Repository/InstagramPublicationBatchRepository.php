<?php

namespace App\Repository;

use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Enum\InstagramPublicationBatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InstagramPublicationBatch> */
final class InstagramPublicationBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramPublicationBatch::class);
    }

    /** @return list<InstagramPublicationBatch> */
    public function findForPublicationOrdered(InstagramPublication $publication): array
    {
        /** @var list<InstagramPublicationBatch> $batches */
        $batches = $this->findBy(
            ['publication' => $publication],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return $batches;
    }

    /** @return list<InstagramPublicationBatch> */
    public function findUnpublishedForPublication(InstagramPublication $publication): array
    {
        /** @var list<InstagramPublicationBatch> $batches */
        $batches = $this->createQueryBuilder('batch')
            ->andWhere('batch.publication = :publication')
            ->andWhere('batch.status IN (:statuses)')
            ->setParameter('publication', $publication)
            ->setParameter('statuses', [
                InstagramPublicationBatchStatus::Pending,
                InstagramPublicationBatchStatus::Processing,
                InstagramPublicationBatchStatus::Failed,
            ])
            ->orderBy('batch.position', 'ASC')
            ->addOrderBy('batch.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $batches;
    }
}
