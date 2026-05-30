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
            $counts[(int) $row['comment_id']] = (int) $row['like_count'];
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

        return array_map(static fn (array $row): int => (int) $row['comment_id'], $rows);
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
}
