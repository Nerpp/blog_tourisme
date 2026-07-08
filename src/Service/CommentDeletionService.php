<?php

namespace App\Service;

use App\Entity\Comment;
use App\Repository\CommentLikeRepository;
use App\Repository\CommentReplyNotificationRepository;
use App\Repository\CommentReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CommentDeletionService
{
    public function __construct(
        private readonly CommentLikeRepository $likeRepository,
        private readonly CommentReportRepository $reportRepository,
        private readonly CommentReplyNotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function deletePhysically(Comment $comment): void
    {
        $comments = $this->collectCommentsToDelete($comment);

        $this->likeRepository->deleteForComments($comments);
        $this->reportRepository->deleteForComments($comments);
        $this->notificationRepository->deleteForComments($comments);

        foreach ($comments as $commentToDelete) {
            $this->entityManager->remove($commentToDelete);
        }
    }

    /** @return list<Comment> */
    private function collectCommentsToDelete(Comment $comment): array
    {
        $comments = [];

        foreach ($comment->getChildren() as $child) {
            foreach ($this->collectCommentsToDelete($child) as $descendant) {
                $comments[] = $descendant;
            }
        }

        $comments[] = $comment;

        return $comments;
    }
}
