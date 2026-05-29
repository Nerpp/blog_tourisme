<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\CommentReplyNotification;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Repository\CommentReplyNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CommentReplyNotificationService
{
    public function __construct(
        private readonly CommentMentionService $mentionService,
        private readonly CommentReplyNotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function createForApprovedComment(Comment $comment): void
    {
        if ($comment->getStatus() !== CommentStatus::Approved) {
            return;
        }

        $author = $comment->getAuthor();
        if (!$author instanceof User) {
            return;
        }

        $recipients = [];
        $parent = $comment->getParent();
        if ($parent instanceof Comment) {
            $parentAuthor = $parent->getAuthor();
            if ($parentAuthor instanceof User && !$this->sameUser($parentAuthor, $author)) {
                $recipients[$parentAuthor->getId() ?? spl_object_id($parentAuthor)] = [
                    'user' => $parentAuthor,
                    'kind' => CommentReplyNotification::KIND_REPLY,
                ];
            }
        }

        foreach ($this->mentionService->findMentionedUsers($comment->getContent() ?? '') as $mentionedUser) {
            if ($this->sameUser($mentionedUser, $author)) {
                continue;
            }

            $key = $mentionedUser->getId() ?? spl_object_id($mentionedUser);
            $recipients[$key] ??= [
                'user' => $mentionedUser,
                'kind' => CommentReplyNotification::KIND_MENTION,
            ];
        }

        foreach ($recipients as $recipient) {
            $user = $recipient['user'] ?? null;
            if (!$user instanceof User) {
                continue;
            }

            if ($comment->getId() !== null && $this->notificationRepository->findOneByRecipientAndComment($user, $comment) !== null) {
                continue;
            }

            $notification = (new CommentReplyNotification())
                ->setRecipient($user)
                ->setComment($comment)
                ->setTriggeredBy($author)
                ->setKind((string) ($recipient['kind'] ?? CommentReplyNotification::KIND_REPLY));

            $this->entityManager->persist($notification);
        }
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left->getId() !== null && $left->getId() === $right->getId();
    }
}
