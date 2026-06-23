<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommentLike> */
class CommentLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentLike::class);
    }

    public function findOneByCommentAndUser(Comment $comment, User $user): ?CommentLike
    {
        return $this->findOneBy([
            'comment' => $comment,
            'user' => $user,
        ]);
    }

    /**
     * @param list<int> $commentIds
     * @return array<int, int>
     */
    public function countByCommentIds(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.comment) AS comment_id, COUNT(l.id) AS like_count')
            ->andWhere('l.comment IN (:comment_ids)')
            ->setParameter('comment_ids', $commentIds)
            ->groupBy('l.comment')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $commentId = $this->positiveInt($row['comment_id'] ?? null);
            $likeCount = $this->nonNegativeInt($row['like_count'] ?? null);
            if ($commentId === null || $likeCount === null) {
                continue;
            }

            $counts[$commentId] = $likeCount;
        }

        return $counts;
    }

    /**
     * @param list<int> $commentIds
     * @return list<int>
     */
    public function findLikedCommentIdsForUser(User $user, array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.comment) AS comment_id')
            ->andWhere('l.user = :user')
            ->andWhere('l.comment IN (:comment_ids)')
            ->setParameter('user', $user)
            ->setParameter('comment_ids', $commentIds)
            ->getQuery()
            ->getArrayResult();

        $likedCommentIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $commentId = $this->positiveInt($row['comment_id'] ?? null);
            if ($commentId !== null) {
                $likedCommentIds[] = $commentId;
            }
        }

        return $likedCommentIds;
    }

    /** @param list<Comment> $comments */
    public function deleteForComments(array $comments): void
    {
        if ($comments === []) {
            return;
        }

        $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->getQuery()
            ->execute();
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

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer >= 0 ? $integer : null;
    }
}
