<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommentReport> */
class CommentReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentReport::class);
    }

    /** @return list<CommentReport> */
    public function findPendingReports(): array
    {
        /** @var list<CommentReport> $reports */
        $reports = $this->createQueryBuilder('r')
            ->addSelect('c', 'a', 'reporter')
            ->leftJoin('r.comment', 'c')
            ->leftJoin('c.author', 'a')
            ->leftJoin('r.reporter', 'reporter')
            ->andWhere('r.status = :status')
            ->setParameter('status', CommentReportStatus::Pending)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $reports;
    }

    public function findOneByCommentAndReporter(Comment $comment, User $reporter): ?CommentReport
    {
        return $this->findOneBy([
            'comment' => $comment,
            'reporter' => $reporter,
        ]);
    }

    /** @return list<CommentReport> */
    public function findRecentForAdmin(int $limit = 50): array
    {
        /** @var list<CommentReport> $reports */
        $reports = $this->createQueryBuilder('r')
            ->addSelect('c', 'a', 'reporter')
            ->leftJoin('r.comment', 'c')
            ->leftJoin('c.author', 'a')
            ->leftJoin('r.reporter', 'reporter')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $reports;
    }

    /** @param list<Comment> $comments */
    public function deleteForComments(array $comments): void
    {
        if ($comments === []) {
            return;
        }

        $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->getQuery()
            ->execute();
    }
}
