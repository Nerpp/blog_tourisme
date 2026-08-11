<?php

namespace App\Repository;

use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InstagramPublicationMedia> */
final class InstagramPublicationMediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramPublicationMedia::class);
    }

    /** @return list<InstagramPublicationMedia> */
    public function findForPublicationOrdered(InstagramPublication $publication): array
    {
        /** @var list<InstagramPublicationMedia> $media */
        $media = $this->findBy(
            ['publication' => $publication],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return $media;
    }

    /** @return list<InstagramPublicationMedia> */
    public function findForBatchOrdered(InstagramPublicationBatch $batch): array
    {
        /** @var list<InstagramPublicationMedia> $media */
        $media = $this->findBy(
            ['batch' => $batch],
            ['batchPosition' => 'ASC', 'id' => 'ASC'],
        );

        return $media;
    }
}
