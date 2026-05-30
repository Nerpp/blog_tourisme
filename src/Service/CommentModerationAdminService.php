<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\User;
use App\Entity\UserModerationWarning;
use App\Enum\CommentStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class CommentModerationAdminService
{
    private const AUTO_BAN_REJECTED_COMMENTS = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentModerationMailer $mailer,
    ) {
    }

    public function approve(Comment $comment, User $admin): void
    {
        $wasApproved = $comment->getStatus() === CommentStatus::Approved;
        $firstApproval = $comment->getApprovedAt() === null;
        $now = new DateTimeImmutable();

        $comment
            ->setStatus(CommentStatus::Approved)
            ->setModerationReason(null)
            ->setModeratedAt($now)
            ->setModeratedBy($admin)
            ->setPublishedAt($comment->getPublishedAt() ?? $now)
            ->setApprovedAt($comment->getApprovedAt() ?? $now);

        if (!$wasApproved && $firstApproval) {
            $comment->getAuthor()?->incrementApprovedCommentsCount();
        }
    }

    public function reject(Comment $comment, User $admin, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('La justification du refus est obligatoire.');
        }

        $wasRejected = $comment->getStatus() === CommentStatus::Rejected;
        $comment
            ->setContent('Commentaire refusé par la modération.')
            ->setStatus(CommentStatus::Rejected)
            ->setModerationReason($reason)
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($admin);

        $autoBanned = false;
        if (!$wasRejected) {
            $autoBanned = $this->warnAuthor($comment, $admin, $reason, true);
        }

        $this->mailer->sendCommentRejected($comment, $reason, $autoBanned);
    }

    public function markAsSpam(Comment $comment, User $admin, ?string $reason = null): void
    {
        $reason = trim((string) $reason) ?: 'Commentaire marqué comme spam.';
        $comment
            ->setStatus(CommentStatus::Spam)
            ->setModerationReason($reason)
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($admin);

        $this->warnAuthor($comment, $admin, $reason, false);
    }

    public function hide(Comment $comment, User $admin, ?string $reason = null): void
    {
        $comment
            ->setStatus(CommentStatus::Spam)
            ->setModerationReason(trim((string) $reason) ?: 'Commentaire masqué par la modération.')
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($admin);
    }

    public function restore(Comment $comment, User $admin): void
    {
        $now = new DateTimeImmutable();

        $comment
            ->setStatus(CommentStatus::Approved)
            ->setModerationReason(null)
            ->setModeratedAt($now)
            ->setModeratedBy($admin)
            ->setPublishedAt($comment->getPublishedAt() ?? $now)
            ->setApprovedAt($comment->getApprovedAt() ?? $now);
    }

    public function softDelete(Comment $comment, User $admin, ?string $reason = null): void
    {
        $comment
            ->setContent('Commentaire supprimé par la modération.')
            ->setStatus(CommentStatus::Deleted)
            ->setModerationReason(trim((string) $reason) ?: 'Commentaire supprimé par la modération.')
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($admin);
    }

    public function banUser(User $user, string $reason): void
    {
        $user
            ->setIsBanned(true)
            ->setBannedAt(new DateTimeImmutable())
            ->setBanReason($reason);
    }

    public function unbanUser(User $user): void
    {
        $user
            ->setIsBanned(false)
            ->setBannedAt(null)
            ->setBanReason(null);
    }

    private function warnAuthor(Comment $comment, User $admin, string $reason, bool $countsAsRejected): bool
    {
        $author = $comment->getAuthor();
        if (!$author instanceof User) {
            return false;
        }

        $warning = (new UserModerationWarning())
            ->setUser($author)
            ->setComment($comment)
            ->setReason($reason)
            ->setCreatedBy($admin);

        $this->entityManager->persist($warning);

        if (!$countsAsRejected) {
            return false;
        }

        $author->incrementRejectedCommentsCount();
        if ($author->getRejectedCommentsCount() < self::AUTO_BAN_REJECTED_COMMENTS || $this->isAdmin($author)) {
            return false;
        }

        $this->banUser($author, 'Bannissement automatique après 3 commentaires refusés.');

        return true;
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
