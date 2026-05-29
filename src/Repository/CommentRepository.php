<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\CommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Comment> */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /** @return list<Comment> */
    public function findApprovedForArticle(Article $article, ?User $viewer = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->where('c.article = :article AND (c.status = :approved OR (c.status = :deleted AND c.publishedAt IS NOT NULL))')
            ->setParameter('article', $article)
            ->setParameter('approved', CommentStatus::Approved)
            ->setParameter('deleted', CommentStatus::Deleted);

        if ($viewer instanceof User) {
            $queryBuilder
                ->orWhere('c.article = :article AND c.author = :viewer AND c.status IN (:owner_statuses)')
                ->setParameter('viewer', $viewer)
                ->setParameter('owner_statuses', [CommentStatus::Pending, CommentStatus::Rejected, CommentStatus::Spam, CommentStatus::Deleted]);
        }

        return $queryBuilder
            ->orderBy('c.publishedAt', 'ASC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findApprovedForPlace(Place $place, ?User $viewer = null): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->where('c.place = :place AND (c.status = :approved OR (c.status = :deleted AND c.publishedAt IS NOT NULL))')
            ->setParameter('place', $place)
            ->setParameter('approved', CommentStatus::Approved)
            ->setParameter('deleted', CommentStatus::Deleted);

        if ($viewer instanceof User) {
            $queryBuilder
                ->orWhere('c.place = :place AND c.author = :viewer AND c.status IN (:owner_statuses)')
                ->setParameter('viewer', $viewer)
                ->setParameter('owner_statuses', [CommentStatus::Pending, CommentStatus::Rejected, CommentStatus::Spam, CommentStatus::Deleted]);
        }

        return $queryBuilder
            ->orderBy('c.publishedAt', 'ASC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findPendingForModeration(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Pending)
            ->orderBy('c.reportedCount', 'DESC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findSpam(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Spam)
            ->orderBy('c.spamScore', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findReportedForModeration(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.reportedCount > 0')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [CommentStatus::Pending, CommentStatus::Approved])
            ->orderBy('c.reportedCount', 'DESC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countApprovedByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.author = :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', CommentStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
