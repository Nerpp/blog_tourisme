<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\CommentReportStatus;
use App\Enum\CommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Comment> */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /** @return list<Comment> */
    public function findApprovedForArticle(Article $article, ?User $viewer = null, string $sort = 'recent'): array
    {
        $commentIds = $this->findVisibleRootIds('article', $article, $sort);

        return $this->findVisibleCommentsByRootIds($commentIds, $viewer);
    }

    /** @return list<Comment> */
    public function findApprovedForPlace(Place $place, ?User $viewer = null, string $sort = 'recent'): array
    {
        $commentIds = $this->findVisibleRootIds('place', $place, $sort);

        return $this->findVisibleCommentsByRootIds($commentIds, $viewer);
    }

    /** @return list<Comment> */
    public function findPendingForModeration(): array
    {
        return $this->createModerationQueryBuilder()
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
        return $this->createModerationQueryBuilder()
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
        return $this->createModerationQueryBuilder()
            ->innerJoin('c.reports', 'pending_report', 'WITH', 'pending_report.status = :report_status')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [CommentStatus::Pending, CommentStatus::Approved])
            ->setParameter('report_status', CommentReportStatus::Pending)
            ->orderBy('c.reportedCount', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    public function countReportedForModeration(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->innerJoin('c.reports', 'r', 'WITH', 'r.status = :report_status')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [CommentStatus::Pending, CommentStatus::Approved])
            ->setParameter('report_status', CommentReportStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Comment> */
    public function findRecentForModeration(int $limit = 100): array
    {
        return $this->createModerationQueryBuilder()
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findApprovedForModeration(int $limit = 100): array
    {
        return $this->createModerationQueryBuilder()
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Approved)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findDeletedForModeration(int $limit = 100): array
    {
        return $this->createModerationQueryBuilder()
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Deleted)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Comment> */
    public function findAllForModeration(int $limit = 100): array
    {
        return $this->createModerationQueryBuilder()
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
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

    /**
     * @param 'article'|'place' $targetField
     * @return list<int>
     */
    private function findVisibleRootIds(string $targetField, Article|Place $target, string $sort): array
    {
        $queryBuilder = $this->createQueryBuilder('c')
            ->select('c.id')
            ->leftJoin('c.likes', 'sort_like')
            ->andWhere(sprintf('c.%s = :target', $targetField))
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.status = :approved')
            ->setParameter('target', $target)
            ->setParameter('approved', CommentStatus::Approved)
            ->groupBy('c.id')
            ->addGroupBy('c.pinnedAt')
            ->addGroupBy('c.createdAt');

        if ($sort === 'popular') {
            $queryBuilder
                ->addSelect('COUNT(sort_like.id) AS HIDDEN like_count')
                ->orderBy('c.pinnedAt', 'DESC')
                ->addOrderBy('like_count', 'DESC')
                ->addOrderBy('c.createdAt', 'DESC');
        } else {
            $queryBuilder
                ->orderBy('c.pinnedAt', 'DESC')
                ->addOrderBy('c.createdAt', 'DESC');
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $queryBuilder->getQuery()->getArrayResult(),
        );
    }

    /**
     * @param list<int> $commentIds
     * @return list<Comment>
     */
    private function findVisibleCommentsByRootIds(array $commentIds, ?User $viewer): array
    {
        if ($commentIds === []) {
            return [];
        }

        $comments = $this->createPublicCommentsQueryBuilder($viewer)
            ->andWhere('c.id IN (:comment_ids)')
            ->setParameter('comment_ids', $commentIds)
            ->getQuery()
            ->getResult();

        $positions = array_flip($commentIds);
        usort(
            $comments,
            static fn (Comment $left, Comment $right): int => ($positions[$left->getId()] ?? 0) <=> ($positions[$right->getId()] ?? 0),
        );

        return $comments;
    }

    private function createPublicCommentsQueryBuilder(?User $viewer): QueryBuilder
    {
        $childVisibility = 'child.status = :approved';
        $rootVisibility = 'c.status = :approved';

        if ($viewer instanceof User) {
            $childVisibility = sprintf('(%s) OR (child.author = :viewer AND child.status IN (:owner_statuses))', $childVisibility);
            $rootVisibility = sprintf('(%s) OR (c.author = :viewer AND c.status IN (:owner_statuses))', $rootVisibility);
        }

        $queryBuilder = $this->createQueryBuilder('c')
            ->select('DISTINCT c, author, child, child_author')
            ->leftJoin('c.author', 'author')
            ->leftJoin(
                'c.children',
                'child',
                'WITH',
                $childVisibility,
            )
            ->leftJoin('child.author', 'child_author')
            ->andWhere('c.parent IS NULL')
            ->andWhere(sprintf('(%s)', $rootVisibility))
            ->setParameter('approved', CommentStatus::Approved)
            ->orderBy('child.publishedAt', 'ASC')
            ->addOrderBy('child.createdAt', 'ASC');

        if ($viewer instanceof User) {
            $queryBuilder
                ->setParameter('viewer', $viewer)
                ->setParameter('owner_statuses', [CommentStatus::Pending, CommentStatus::Rejected]);
        }

        return $queryBuilder;
    }

    private function createModerationQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->select('DISTINCT c, author, article, place, reports, reporter')
            ->leftJoin('c.author', 'author')
            ->leftJoin('c.article', 'article')
            ->leftJoin('c.place', 'place')
            ->leftJoin('c.reports', 'reports')
            ->leftJoin('reports.reporter', 'reporter');
    }
}
