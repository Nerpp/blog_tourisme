<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\CommentReportStatus;
use App\Enum\CommentStatus;
use DateTimeImmutable;
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

        return $this->findVisibleCommentsByRootIds($commentIds);
    }

    /** @return list<Comment> */
    public function findApprovedForPlace(Place $place, ?User $viewer = null, string $sort = 'recent'): array
    {
        $commentIds = $this->findVisibleRootIds('place', $place, $sort);

        return $this->findVisibleCommentsByRootIds($commentIds);
    }

    /** @return list<Comment> */
    public function findPendingForModeration(): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Pending)
            ->orderBy('c.reportedCount', 'DESC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findSpam(): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Spam)
            ->orderBy('c.spamScore', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findHiddenForModeration(): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [CommentStatus::HiddenByAdmin, CommentStatus::Spam])
            ->orderBy('c.moderatedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findReportedForModeration(): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->innerJoin('c.reports', 'pending_report', 'WITH', 'pending_report.status = :report_status')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [
                CommentStatus::HiddenPendingReport,
                CommentStatus::Approved,
                CommentStatus::Pending,
                CommentStatus::Spam,
            ])
            ->setParameter('report_status', CommentReportStatus::Pending)
            ->orderBy('c.reportedCount', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    public function countReportedForModeration(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->innerJoin('c.reports', 'r', 'WITH', 'r.status = :report_status')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [
                CommentStatus::HiddenPendingReport,
                CommentStatus::Approved,
                CommentStatus::Pending,
                CommentStatus::Spam,
            ])
            ->setParameter('report_status', CommentReportStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Comment> */
    public function findDismissedReportsForModeration(int $limit = 100): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->innerJoin('c.reports', 'dismissed_report', 'WITH', 'dismissed_report.status = :report_status')
            ->setParameter('report_status', CommentReportStatus::Dismissed)
            ->orderBy('dismissed_report.reviewedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findRecentForModeration(int $limit = 100): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findApprovedForModeration(int $limit = 100): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Approved)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findDeletedForModeration(int $limit = 100): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->andWhere('c.status = :status')
            ->setParameter('status', CommentStatus::Deleted)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $comments;
    }

    /** @return list<Comment> */
    public function findAllForModeration(int $limit = 100): array
    {
        /** @var list<Comment> $comments */
        $comments = $this->createModerationQueryBuilder()
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $comments;
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

    public function hasRecentDuplicate(Comment $comment, DateTimeImmutable $since, ?Comment $exclude = null): bool
    {
        $author = $comment->getAuthor();
        $content = trim((string) $comment->getContent());
        if ($author === null || $content === '') {
            return false;
        }

        $queryBuilder = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.content = :content')
            ->andWhere('c.createdAt >= :since')
            ->andWhere('c.status != :deleted')
            ->setParameter('content', $content)
            ->setParameter('since', $since)
            ->setParameter('deleted', CommentStatus::Deleted);

        $queryBuilder
            ->andWhere('c.author = :author')
            ->setParameter('author', $author);

        if ($exclude instanceof Comment && $exclude->getId() !== null) {
            $queryBuilder
                ->andWhere('c != :excluded_comment')
                ->setParameter('excluded_comment', $exclude);
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult() > 0;
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

        $rows = $queryBuilder->getQuery()->getArrayResult();
        $commentIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $commentId = $this->positiveInt($row['id'] ?? null);
            if ($commentId !== null) {
                $commentIds[] = $commentId;
            }
        }

        return $commentIds;
    }

    /**
     * @param list<int> $commentIds
     * @return list<Comment>
     */
    private function findVisibleCommentsByRootIds(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        /** @var list<Comment> $comments */
        $comments = $this->createPublicCommentsQueryBuilder()
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

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function createPublicCommentsQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->select('DISTINCT c, author, child, child_author')
            ->leftJoin('c.author', 'author')
            ->leftJoin(
                'c.children',
                'child',
                'WITH',
                'child.status = :approved',
            )
            ->leftJoin('child.author', 'child_author')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.status = :approved')
            ->setParameter('approved', CommentStatus::Approved)
            ->orderBy('child.publishedAt', 'ASC')
            ->addOrderBy('child.createdAt', 'ASC');
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
