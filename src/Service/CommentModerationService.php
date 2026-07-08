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
        private readonly int $reportThreshold = 3,
    ) {
    }

    public function moderateNew(Comment $comment): void
    {
        [$score, $reasons] = $this->analyze($comment->getContent() ?? '');
        $status = $score >= self::HIGH_SPAM_SCORE ? CommentStatus::Spam : CommentStatus::Approved;

        $this->applyModeration($comment, $status, $score, $reasons);
    }

    public function moderateEdited(Comment $comment, User $editor, bool $isAdmin, CommentStatus $previousStatus): void
    {
        if (!$isAdmin && $previousStatus !== CommentStatus::Approved) {
            $comment->setStatus($previousStatus);

            return;
        }

        [$score, $reasons] = $this->analyze($comment->getContent() ?? '');
        $status = $score >= self::HIGH_SPAM_SCORE && !$isAdmin ? CommentStatus::Spam : CommentStatus::Approved;

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

        $this->hideForPendingReportReview($comment);
    }

    public function hideForPendingReportReview(Comment $comment): void
    {
        if ($comment->getStatus() !== CommentStatus::Approved) {
            return;
        }

        $comment
            ->setStatus(CommentStatus::HiddenPendingReport)
            ->setModerationReason('Signalement en attente de modération.')
            ->setModeratedAt(new DateTimeImmutable());
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

        if ($firstApproval) {
            $comment->getAuthor()?->incrementApprovedCommentsCount();
        }
    }

}
