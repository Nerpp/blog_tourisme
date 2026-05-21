<?php

namespace App\Repository;

use App\Entity\PublicationNotificationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PublicationNotificationLog> */
class PublicationNotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationNotificationLog::class);
    }

    public function hasNotificationBeenSent(string $contentType, int $contentId): bool
    {
        return $this->findOneBy([
            'contentType' => $contentType,
            'contentId' => $contentId,
        ]) instanceof PublicationNotificationLog;
    }
}
