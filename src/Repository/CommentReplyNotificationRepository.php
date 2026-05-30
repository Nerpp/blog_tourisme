<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentReplyNotification;
use App\Entity\User;
use App\Enum\CommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CommentReplyNotification> */
class CommentReplyNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentReplyNotification::class);
    }

    public function countUnreadForRecipient(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->innerJoin('n.comment', 'c')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('c.status = :approved')
            ->setParameter('recipient', $recipient)
            ->setParameter('approved', CommentStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CommentReplyNotification> */
    public function findRecentForRecipient(User $recipient, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('c', 'a', 'triggered_by', 'article', 'place')
            ->innerJoin('n.comment', 'c')
            ->leftJoin('c.author', 'a')
            ->leftJoin('n.triggeredBy', 'triggered_by')
            ->leftJoin('c.article', 'article')
            ->leftJoin('c.place', 'place')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('c.status = :approved')
            ->setParameter('recipient', $recipient)
            ->setParameter('approved', CommentStatus::Approved)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByRecipientAndComment(User $recipient, Comment $comment): ?CommentReplyNotification
    {
        return $this->findOneBy([
            'recipient' => $recipient,
            'comment' => $comment,
        ]);
    }

    public function deleteAllForRecipient(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->execute();
    }

    /** @param list<Comment> $comments */
    public function deleteForComments(array $comments): void
    {
        if ($comments === []) {
            return;
        }

        $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.comment IN (:comments)')
            ->setParameter('comments', $comments)
            ->getQuery()
            ->execute();
    }
}
