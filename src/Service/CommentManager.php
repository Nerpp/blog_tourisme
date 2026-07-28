<?php

namespace App\Service;

use App\Contract\CommentableContentInterface;
use App\Entity\Comment;
use App\Entity\CommentThread;
use App\Entity\User;
use App\Enum\CommentStatus;
use Doctrine\ORM\EntityManagerInterface;

final class CommentManager
{
    public function __construct(
        private readonly CommentSpamGuard $spamGuard,
        private readonly CommentModerationService $moderationService,
        private readonly CommentReplyNotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function createForContent(
        CommentableContentInterface $content,
        User $author,
        ?string $ipAddress,
        ?string $userAgent,
    ): Comment {
        if (!$content->isPublished()) {
            throw new \InvalidArgumentException('Un contenu non public ne peut pas recevoir de commentaire.');
        }

        return (new Comment())
            ->setThread($content->getCommentThread())
            ->setAuthor($author)
            ->setIpAddress($ipAddress)
            ->setUserAgent($userAgent);
    }

    public function createReply(
        Comment $parent,
        User $author,
        string $content,
        ?string $ipAddress,
        ?string $userAgent,
    ): Comment {
        if (!$parent->getThread() instanceof CommentThread) {
            throw new \InvalidArgumentException('Le commentaire parent ne possède pas de fil.');
        }

        return (new Comment())
            ->setParent($parent)
            ->setAuthor($author)
            ->setContent(trim($content))
            ->setIpAddress($ipAddress)
            ->setUserAgent($userAgent);
    }

    public function publish(Comment $comment, ?Comment $excludeFromDuplicateCheck = null): ?string
    {
        if (($spamMessage = $this->spamGuard->validate($comment, $excludeFromDuplicateCheck)) !== null) {
            return $spamMessage;
        }

        $this->moderationService->moderateNew($comment);
        $this->entityManager->persist($comment);

        if ($comment->getStatus() === CommentStatus::Approved) {
            $this->notificationService->createForApprovedComment($comment);
        }

        $this->entityManager->flush();

        return null;
    }
}
