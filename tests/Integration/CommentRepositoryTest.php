<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\CommentReport;
use App\Entity\User;
use App\Enum\CommentReportReason;
use App\Enum\CommentReportStatus;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Repository\CommentRepository;
use DateTimeImmutable;

final class CommentRepositoryTest extends IntegrationTestCase
{
    public function testAnonymousReaderOnlyGetsApprovedReplies(): void
    {
        $author = $this->createUser();
        $article = $this->article($author);
        $root = $this->comment($author, $article, CommentStatus::Approved, 'Racine publique pour les réponses.');
        $approved = $this->reply($author, $article, $root, CommentStatus::Approved, 'Réponse publique approuvée.');
        $this->reply($author, $article, $root, CommentStatus::Pending, 'Réponse encore en attente.');
        $this->reply($author, $article, $root, CommentStatus::Rejected, 'Réponse rejetée et privée.');
        $this->reply($author, $article, $root, CommentStatus::HiddenPendingReport, 'Réponse masquée après signalement.');
        $this->reply($author, $article, $root, CommentStatus::HiddenByAdmin, 'Réponse masquée par administration.');
        $this->reply($author, $article, $root, CommentStatus::Deleted, 'Réponse supprimée administrativement.');
        $this->flushAndClear();

        $article = $this->reloadArticle($article);
        $results = $this->repository()->findApprovedForArticle($article);

        self::assertCount(1, $results);
        self::assertSame($root->getId(), $results[0]->getId());
        self::assertSame(
            [$approved->getId()],
            array_map(static fn (Comment $comment): ?int => $comment->getId(), $results[0]->getChildren()->toArray()),
        );
    }

    public function testAuthenticatedAuthorOnlyGetsApprovedRootsAndRepliesIncludingTheirOwn(): void
    {
        $viewer = $this->createUser();
        $otherAuthor = $this->createUser();
        $article = $this->article($viewer);
        $root = $this->comment($viewer, $article, CommentStatus::Approved, 'Racine personnelle publique.');
        $this->comment($viewer, $article, CommentStatus::Pending, 'Racine personnelle en attente.');
        $this->comment($viewer, $article, CommentStatus::Rejected, 'Racine personnelle rejetée.');
        $this->comment($viewer, $article, CommentStatus::Spam, 'Racine personnelle classée comme spam.');
        $this->comment($viewer, $article, CommentStatus::HiddenPendingReport, 'Racine personnelle signalée et masquée.');
        $this->comment($viewer, $article, CommentStatus::HiddenByAdmin, 'Racine personnelle masquée par administration.');
        $this->comment($viewer, $article, CommentStatus::Deleted, 'Racine personnelle supprimée.');
        $approved = $this->reply($otherAuthor, $article, $root, CommentStatus::Approved, 'Réponse publique d’un autre auteur.');
        $this->reply($viewer, $article, $root, CommentStatus::Pending, 'Réponse personnelle en attente.');
        $this->reply($viewer, $article, $root, CommentStatus::Rejected, 'Réponse personnelle rejetée.');
        $this->reply($viewer, $article, $root, CommentStatus::Spam, 'Réponse personnelle classée comme spam.');
        $this->reply($otherAuthor, $article, $root, CommentStatus::Pending, 'Réponse privée d’un autre auteur.');
        $this->reply($viewer, $article, $root, CommentStatus::HiddenPendingReport, 'Réponse personnelle signalée et masquée.');
        $this->reply($viewer, $article, $root, CommentStatus::HiddenByAdmin, 'Réponse personnelle masquée par administration.');
        $this->reply($viewer, $article, $root, CommentStatus::Deleted, 'Réponse personnelle supprimée.');
        $this->entityManager->flush();
        $articleId = $article->getId();
        $viewerId = $viewer->getId();
        $this->entityManager->clear();

        $article = $this->findArticle($articleId);
        $viewer = $this->entityManager->find(User::class, $viewerId);
        self::assertInstanceOf(User::class, $viewer);
        $viewerResults = $this->repository()->findApprovedForArticle($article, $viewer);
        self::assertCount(1, $viewerResults);
        self::assertSame($root->getId(), $viewerResults[0]->getId());
        self::assertSame(
            [$approved->getId()],
            array_map(static fn (Comment $comment): ?int => $comment->getId(), $viewerResults[0]->getChildren()->toArray()),
        );
    }

    public function testModerationQueriesFilterStatusesAndReports(): void
    {
        $author = $this->createUser();
        $article = $this->article($author);

        $pending = $this->comment($author, $article, CommentStatus::Pending, 'Commentaire en attente pour moderation.')
            ->setReportedCount(3)
            ->setCreatedAt(new DateTimeImmutable('2036-01-01 09:00:00'));
        $approved = $this->comment($author, $article, CommentStatus::Approved, 'Commentaire approuve pour moderation.')
            ->setCreatedAt(new DateTimeImmutable('2036-01-02 09:00:00'));
        $spam = $this->comment($author, $article, CommentStatus::Spam, 'Commentaire spam pour moderation.')
            ->setSpamScore(98)
            ->setModeratedAt(new DateTimeImmutable('2036-01-03 09:00:00'))
            ->setCreatedAt(new DateTimeImmutable('2036-01-03 08:00:00'));
        $hidden = $this->comment($author, $article, CommentStatus::HiddenByAdmin, 'Commentaire masque admin pour moderation.')
            ->setModeratedAt(new DateTimeImmutable('2036-01-04 09:00:00'))
            ->setCreatedAt(new DateTimeImmutable('2036-01-04 08:00:00'));
        $deleted = $this->comment($author, $article, CommentStatus::Deleted, 'Commentaire supprime pour moderation.')
            ->setCreatedAt(new DateTimeImmutable('2036-01-05 09:00:00'));
        $reported = $this->comment($author, $article, CommentStatus::HiddenPendingReport, 'Commentaire signale pour moderation.')
            ->setReportedCount(2)
            ->setCreatedAt(new DateTimeImmutable('2036-01-06 09:00:00'));
        $dismissed = $this->comment($author, $article, CommentStatus::Approved, 'Commentaire signale puis restaure.')
            ->setCreatedAt(new DateTimeImmutable('2036-01-07 09:00:00'));

        $this->report($reported, $this->createUser(), CommentReportStatus::Pending, new DateTimeImmutable('2036-01-06 10:00:00'));
        $this->report($dismissed, $this->createUser(), CommentReportStatus::Dismissed, new DateTimeImmutable('2036-01-07 10:00:00'));
        $this->entityManager->flush();

        $ids = [
            'pending' => $this->id($pending),
            'approved' => $this->id($approved),
            'spam' => $this->id($spam),
            'hidden' => $this->id($hidden),
            'deleted' => $this->id($deleted),
            'reported' => $this->id($reported),
            'dismissed' => $this->id($dismissed),
        ];
        $authorId = $author->getId();
        self::assertNotNull($authorId);
        $this->entityManager->clear();

        $repository = $this->repository();

        self::assertContains($ids['pending'], $this->resultIds($repository->findPendingForModeration()));
        self::assertContains($ids['spam'], $this->resultIds($repository->findSpam()));
        self::assertContains($ids['spam'], $this->resultIds($repository->findHiddenForModeration()));
        self::assertContains($ids['hidden'], $this->resultIds($repository->findHiddenForModeration()));
        self::assertNotContains($ids['reported'], $this->resultIds($repository->findHiddenForModeration()));
        self::assertContains($ids['reported'], $this->resultIds($repository->findReportedForModeration()));
        self::assertGreaterThanOrEqual(1, $repository->countReportedForModeration());
        self::assertContains($ids['dismissed'], $this->resultIds($repository->findDismissedReportsForModeration()));
        self::assertContains($ids['approved'], $this->resultIds($repository->findApprovedForModeration()));
        self::assertContains($ids['deleted'], $this->resultIds($repository->findDeletedForModeration()));
        self::assertContains($ids['dismissed'], $this->resultIds($repository->findRecentForModeration()));
        self::assertContains($ids['pending'], $this->resultIds($repository->findAllForModeration()));

        $author = $this->entityManager->find(User::class, $authorId);
        self::assertInstanceOf(User::class, $author);
        self::assertSame(2, $repository->countApprovedByUser($author));
    }

    public function testRecentDuplicateDetectionHandlesEmptyExcludedAndMatchingComments(): void
    {
        $author = $this->createUser();
        $article = $this->article($author);
        $content = 'Commentaire doublon exact pour repository.';
        $existing = $this->comment($author, $article, CommentStatus::Approved, $content)
            ->setCreatedAt(new DateTimeImmutable('2036-02-01 10:00:00'));
        $this->entityManager->flush();
        $since = new DateTimeImmutable('2036-02-01 09:30:00');

        $probe = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent((string) $existing->getContent());
        $emptyProbe = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent('   ');

        self::assertTrue($this->repository()->hasRecentDuplicate($probe, $since));
        self::assertFalse($this->repository()->hasRecentDuplicate($probe, $since, $existing));
        self::assertFalse($this->repository()->hasRecentDuplicate($emptyProbe, $since));
    }

    private function article(User $author): Article
    {
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle($this->uniqueToken('comment-repository-article'))
            ->setSlug($this->uniqueToken('comment-repository-slug'))
            ->setContent('Contenu publié utilisé pour tester les commentaires.')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
        $this->entityManager->persist($author);
        $this->entityManager->persist($article);

        return $article;
    }

    private function comment(
        User $author,
        Article $article,
        CommentStatus $status,
        string $content,
        ?Comment $parent = null,
    ): Comment {
        if (!$this->entityManager->contains($author)) {
            $this->entityManager->persist($author);
        }

        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setParent($parent)
            ->setContent($content.' '.$this->uniqueToken('comment'))
            ->setStatus($status);

        if ($status === CommentStatus::Approved) {
            $now = new DateTimeImmutable();
            $comment
                ->setApprovedAt($now)
                ->setPublishedAt($now);
        }

        $this->entityManager->persist($comment);

        return $comment;
    }

    private function reply(User $author, Article $article, Comment $parent, CommentStatus $status, string $content): Comment
    {
        return $this->comment($author, $article, $status, $content, $parent);
    }

    private function report(Comment $comment, User $reporter, CommentReportStatus $status, DateTimeImmutable $reviewedAt): CommentReport
    {
        $report = (new CommentReport())
            ->setComment($comment)
            ->setReporter($reporter)
            ->setReason(CommentReportReason::Spam)
            ->setStatus($status);

        if ($status !== CommentReportStatus::Pending) {
            $report->setReviewedAt($reviewedAt);
        }

        $this->entityManager->persist($reporter);
        $this->entityManager->persist($report);

        return $report;
    }

    private function repository(): CommentRepository
    {
        $repository = $this->entityManager->getRepository(Comment::class);
        self::assertInstanceOf(CommentRepository::class, $repository);

        return $repository;
    }

    private function reloadArticle(Article $article): Article
    {
        return $this->findArticle($article->getId());
    }

    private function findArticle(?int $articleId): Article
    {
        self::assertNotNull($articleId);
        $article = $this->entityManager->find(Article::class, $articleId);
        self::assertInstanceOf(Article::class, $article);

        return $article;
    }

    private function id(Comment $comment): int
    {
        $id = $comment->getId();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @param list<Comment> $comments
     * @return list<int>
     */
    private function resultIds(array $comments): array
    {
        return array_values(array_filter(
            array_map(static fn (Comment $comment): ?int => $comment->getId(), $comments),
            static fn (?int $id): bool => $id !== null,
        ));
    }

    private function flushAndClear(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
