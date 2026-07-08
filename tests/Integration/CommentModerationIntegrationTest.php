<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\ModerationKeyword;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Enum\ModerationKeywordType;
use App\Repository\CommentRepository;
use App\Service\CommentModerationService;
use DateTimeImmutable;

final class CommentModerationIntegrationTest extends IntegrationTestCase
{
    public function testNonVisibleCommentsAreNotPublicForAnonymousReaders(): void
    {
        $token = bin2hex(random_bytes(4));
        $author = $this->createUser();
        $article = $this->createArticle($token, $author);
        $approved = $this->createComment($author, $article, CommentStatus::Approved, 'Approved public comment '.$token);
        $rejected = $this->createComment($author, $article, CommentStatus::Rejected, 'Rejected private comment '.$token);
        $reported = $this->createComment($author, $article, CommentStatus::HiddenPendingReport, 'Reported hidden private comment '.$token);
        $hiddenByAdmin = $this->createComment($author, $article, CommentStatus::HiddenByAdmin, 'Admin hidden private comment '.$token);
        $deleted = $this->createComment($author, $article, CommentStatus::Deleted, 'Deleted private comment '.$token);

        $this->entityManager->persist($author);
        $this->entityManager->persist($article);
        $this->entityManager->persist($approved);
        $this->entityManager->persist($rejected);
        $this->entityManager->persist($reported);
        $this->entityManager->persist($hiddenByAdmin);
        $this->entityManager->persist($deleted);
        $this->entityManager->flush();

        $visibleComments = $this->commentRepository()->findApprovedForArticle($article);

        self::assertContains($approved, $visibleComments);
        self::assertNotContains($rejected, $visibleComments);
        self::assertNotContains($reported, $visibleComments);
        self::assertNotContains($hiddenByAdmin, $visibleComments);
        self::assertNotContains($deleted, $visibleComments);
    }

    public function testBlockedModerationKeywordRaisesSpamScoreThroughRealRepository(): void
    {
        $keyword = (new ModerationKeyword())
            ->setKeyword('integration-blocked-keyword')
            ->setType(ModerationKeywordType::Blocked)
            ->setEnabled(true);
        $comment = (new Comment())
            ->setAuthor($this->createUser())
            ->setContent('This otherwise normal comment contains integration-blocked-keyword.');

        $this->entityManager->persist($keyword);
        $this->entityManager->flush();

        $this->moderationService()->moderateNew($comment);

        self::assertSame(CommentStatus::Spam, $comment->getStatus());
        self::assertSame(100, $comment->getSpamScore());
        self::assertStringContainsString('integration-blocked-keyword', (string) $comment->getModerationReason());
        self::assertNull($comment->getApprovedAt());
        self::assertNull($comment->getPublishedAt());
    }

    public function testReportThresholdMarksApprovedCommentForModerationReview(): void
    {
        $comment = (new Comment())
            ->setAuthor($this->createUser())
            ->setContent('This public comment has enough reports to require review.')
            ->setStatus(CommentStatus::Approved)
            ->setReportedCount(3);

        $this->moderationService()->applyReportThreshold($comment);

        self::assertSame(CommentStatus::HiddenPendingReport, $comment->getStatus());
        self::assertSame('Signalement en attente de modération.', $comment->getModerationReason());
        self::assertNotNull($comment->getModeratedAt());
    }

    private function createArticle(string $token, User $author): Article
    {
        return (new Article())
            ->setAuthor($author)
            ->setTitle('Integration article '.$token)
            ->setSlug('integration-article-'.$token)
            ->setContent('Article content for comment integration tests.')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
    }

    private function createComment(User $author, Article $article, CommentStatus $status, string $content): Comment
    {
        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent($content)
            ->setStatus($status);

        if ($status === CommentStatus::Approved) {
            $now = new DateTimeImmutable();
            $comment
                ->setApprovedAt($now)
                ->setPublishedAt($now);
        }

        return $comment;
    }

    private function commentRepository(): CommentRepository
    {
        $repository = $this->service(CommentRepository::class);
        self::assertInstanceOf(CommentRepository::class, $repository);

        return $repository;
    }

    private function moderationService(): CommentModerationService
    {
        $service = $this->service(CommentModerationService::class);
        self::assertInstanceOf(CommentModerationService::class, $service);

        return $service;
    }
}
