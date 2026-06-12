<?php

namespace App\Service;

use App\Entity\Comment;
use App\Repository\CommentRepository;
use DateTimeImmutable;

final class CommentSpamGuard
{
    private const MAX_LINKS = 2;
    private const DUPLICATE_WINDOW = '-15 minutes';

    public function __construct(
        private readonly CommentRepository $commentRepository,
    ) {
    }

    public function validate(Comment $comment, ?Comment $exclude = null): ?string
    {
        $content = trim((string) $comment->getContent());
        $comment->setContent($content);

        if ($this->countLinks($content) > self::MAX_LINKS) {
            return 'Votre commentaire contient trop de liens pour être publié automatiquement.';
        }

        if ($this->commentRepository->hasRecentDuplicate($comment, new DateTimeImmutable(self::DUPLICATE_WINDOW), $exclude)) {
            return 'Ce commentaire a déjà été envoyé récemment.';
        }

        return null;
    }

    private function countLinks(string $content): int
    {
        return preg_match_all('~https?://|www\.~i', $content) ?: 0;
    }
}
