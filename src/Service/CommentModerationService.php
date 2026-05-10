<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ModerationKeywordType;
use App\Repository\ModerationKeywordRepository;
use DateTimeImmutable;

class CommentModerationService
{
    private const MEDIUM_SPAM_SCORE = 25;
    private const HIGH_SPAM_SCORE = 70;

    /** @var list<string> */
    private const BUILT_IN_SPAM_KEYWORDS = [
        'backlink',
        'casino',
        'crypto',
        'free money',
        'loan',
        'make money',
        'porn',
        'seo service',
        'viagra',
    ];

    public function __construct(
        private readonly ModerationKeywordRepository $keywordRepository,
        private readonly int $autoApproveAfter = 3,
        private readonly int $reportThreshold = 3,
    ) {
    }

    public function moderateNew(Comment $comment): void
    {
        [$score, $reasons] = $this->analyze($comment->getContent() ?? '');

        $status = match (true) {
            $score >= self::HIGH_SPAM_SCORE => CommentStatus::Spam,
            $score >= self::MEDIUM_SPAM_SCORE => CommentStatus::Pending,
            $this->canAutoApprove($comment->getAuthor()) => CommentStatus::Approved,
            default => CommentStatus::Pending,
        };

        $this->applyModeration($comment, $status, $score, $reasons);
    }

    public function moderateEdited(Comment $comment, User $editor, bool $isAdmin, CommentStatus $previousStatus): void
    {
        [$score, $reasons] = $this->analyze($comment->getContent() ?? '');

        $status = match (true) {
            $score >= self::HIGH_SPAM_SCORE => CommentStatus::Spam,
            $score >= self::MEDIUM_SPAM_SCORE => CommentStatus::Pending,
            $isAdmin && $previousStatus === CommentStatus::Approved => CommentStatus::Approved,
            $this->sameUser($comment->getAuthor(), $editor) && $this->canAutoApprove($editor) => CommentStatus::Approved,
            default => CommentStatus::Pending,
        };

        $this->applyModeration($comment, $status, $score, $reasons);
    }

    public function applyReportThreshold(Comment $comment): void
    {
        if ($comment->getReportedCount() < $this->reportThreshold) {
            return;
        }

        if ($comment->getStatus() !== CommentStatus::Approved) {
            return;
        }

        $comment
            ->setStatus(CommentStatus::Pending)
            ->setModerationReason('Seuil de signalements atteint.')
            ->setModeratedAt(new DateTimeImmutable());
    }

    public function approve(Comment $comment, ?User $moderator = null): void
    {
        $this->applyModeration($comment, CommentStatus::Approved, $comment->getSpamScore(), [], $moderator);
    }

    /** @return array{0:int, 1:list<string>} */
    private function analyze(string $content): array
    {
        $content = trim($content);
        $normalized = mb_strtolower($content);
        $score = 0;
        $reasons = [];

        $length = mb_strlen($content);
        if ($length === 0) {
            $score += 80;
            $reasons[] = 'Contenu vide.';
        } elseif ($length < 10) {
            $score += 30;
            $reasons[] = 'Contenu trop court.';
        }

        if ($length > 5000) {
            $score += 30;
            $reasons[] = 'Contenu trop long.';
        }

        $linkCount = preg_match_all('~https?://|www\.~i', $content);
        if ($linkCount > 2) {
            $score += min(40, ($linkCount - 2) * 15);
            $reasons[] = 'Nombre de liens eleve.';
        }

        if (preg_match('/(.)\1{8,}/u', $content) === 1) {
            $score += 20;
            $reasons[] = 'Repetition excessive de caracteres.';
        }

        foreach (self::BUILT_IN_SPAM_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                $score += 35;
                $reasons[] = sprintf('Mot-cle suspect: %s.', $keyword);
            }
        }

        foreach ($this->keywordRepository->findEnabledKeywords() as $keyword) {
            $needle = mb_strtolower((string) $keyword->getKeyword());
            if ($needle === '' || !str_contains($normalized, $needle)) {
                continue;
            }

            match ($keyword->getType()) {
                ModerationKeywordType::Review => $score = max($score, self::MEDIUM_SPAM_SCORE),
                ModerationKeywordType::Spam => $score = max($score, self::HIGH_SPAM_SCORE),
                ModerationKeywordType::Blocked => $score = 100,
            };

            $reasons[] = sprintf('Mot-cle de moderation %s: %s.', $keyword->getType()->value, $keyword->getKeyword());
        }

        return [min(100, $score), array_values(array_unique($reasons))];
    }

    /** @param list<string> $reasons */
    private function applyModeration(
        Comment $comment,
        CommentStatus $status,
        int $score,
        array $reasons,
        ?User $moderator = null,
    ): void {
        $wasApproved = $comment->getStatus() === CommentStatus::Approved;
        $firstApproval = $comment->getApprovedAt() === null;
        $now = new DateTimeImmutable();

        $comment
            ->setStatus($status)
            ->setSpamScore($score)
            ->setModerationReason($reasons === [] ? null : implode(' ', $reasons))
            ->setModeratedAt($now)
            ->setModeratedBy($moderator);

        if ($status !== CommentStatus::Approved) {
            return;
        }

        $comment->setPublishedAt($comment->getPublishedAt() ?? $now);
        $comment->setApprovedAt($comment->getApprovedAt() ?? $now);

        if (!$wasApproved && $firstApproval) {
            $comment->getAuthor()?->incrementApprovedCommentsCount();
        }
    }

    private function canAutoApprove(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        return $user->isTrustedCommenter()
            || $user->getApprovedCommentsCount() >= $this->autoApproveAfter;
    }

    private function sameUser(?User $left, User $right): bool
    {
        return $left?->getId() !== null && $left->getId() === $right->getId();
    }
}
