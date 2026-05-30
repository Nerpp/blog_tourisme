<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentLikeRepository;

final class CommentReactionViewService
{
    public function __construct(
        private readonly CommentLikeRepository $likeRepository,
    ) {
    }

    /**
     * @param list<Comment> $comments
     * @return array{comment_count: int, like_counts: array<int, int>, liked_comment_ids: list<int>}
     */
    public function buildContext(array $comments, ?User $viewer): array
    {
        $commentIds = $this->collectCommentIds($comments);

        return [
            'comment_count' => count($commentIds),
            'like_counts' => $this->likeRepository->countByCommentIds($commentIds),
            'liked_comment_ids' => $viewer instanceof User
                ? $this->likeRepository->findLikedCommentIdsForUser($viewer, $commentIds)
                : [],
        ];
    }

    /**
     * @param list<Comment> $comments
     * @return list<int>
     */
    private function collectCommentIds(array $comments): array
    {
        $ids = [];

        foreach ($comments as $comment) {
            if (!$comment instanceof Comment || $comment->getId() === null) {
                continue;
            }

            $ids[] = $comment->getId();
            foreach ($comment->getChildren() as $child) {
                if ($child->getId() !== null) {
                    $ids[] = $child->getId();
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
