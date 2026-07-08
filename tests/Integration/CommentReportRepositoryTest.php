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
use App\Repository\CommentReportRepository;
use DateTimeImmutable;

final class CommentReportRepositoryTest extends IntegrationTestCase
{
    public function testFindPendingReportsExcludesProcessedReportsAndOrdersOldestFirst(): void
    {
        $author = $this->createUser();
        $article = $this->article($author);
        $comment = $this->comment($author, $article);
        $oldestReporter = $this->createUser();
        $newestReporter = $this->createUser();
        $reviewedReporter = $this->createUser();
        $dismissedReporter = $this->createUser();
        $oldest = $this->report($comment, $oldestReporter, CommentReportStatus::Pending, new DateTimeImmutable('2035-01-01 10:00:00'));
        $newest = $this->report($comment, $newestReporter, CommentReportStatus::Pending, new DateTimeImmutable('2035-01-02 10:00:00'));
        $reviewed = $this->report($comment, $reviewedReporter, CommentReportStatus::Reviewed, new DateTimeImmutable('2034-12-30 10:00:00'));
        $dismissed = $this->report($comment, $dismissedReporter, CommentReportStatus::Dismissed, new DateTimeImmutable('2034-12-31 10:00:00'));
        $this->entityManager->flush();

        $results = $this->repository()->findPendingReports();
        $resultIds = array_map(static fn (CommentReport $report): ?int => $report->getId(), $results);
        $createdReportIds = [$oldest->getId(), $newest->getId()];
        $matchingIds = array_values(array_filter(
            $resultIds,
            static fn (?int $id): bool => in_array($id, $createdReportIds, true),
        ));

        self::assertSame($createdReportIds, $matchingIds);
        self::assertNotContains($reviewed->getId(), $resultIds);
        self::assertNotContains($dismissed->getId(), $resultIds);
        self::assertSame($comment, $oldest->getComment());
        self::assertSame($oldestReporter, $oldest->getReporter());
        self::assertSame($newestReporter, $newest->getReporter());
    }

    public function testDeleteForCommentsDeletesOnlyReportsAttachedToSelectedComments(): void
    {
        $author = $this->createUser();
        $article = $this->article($author);
        $selectedComment = $this->comment($author, $article);
        $preservedComment = $this->comment($author, $article);
        $this->report($selectedComment, $this->createUser(), CommentReportStatus::Pending, new DateTimeImmutable('2035-02-01'));
        $this->report($selectedComment, $this->createUser(), CommentReportStatus::Reviewed, new DateTimeImmutable('2035-02-02'));
        $preservedReport = $this->report($preservedComment, $this->createUser(), CommentReportStatus::Pending, new DateTimeImmutable('2035-02-03'));
        $this->entityManager->flush();
        $repository = $this->repository();

        $repository->deleteForComments([$selectedComment]);
        $repository->deleteForComments([]);

        self::assertSame(0, $repository->count(['comment' => $selectedComment]));
        self::assertSame(1, $repository->count(['comment' => $preservedComment]));
        self::assertSame($preservedReport->getId(), $repository->findOneBy(['comment' => $preservedComment])?->getId());
    }

    private function article(User $author): Article
    {
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle($this->uniqueToken('comment-report-article'))
            ->setSlug($this->uniqueToken('comment-report-slug'))
            ->setContent('Contenu publié utilisé pour tester les signalements.')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
        $this->entityManager->persist($author);
        $this->entityManager->persist($article);

        return $article;
    }

    private function comment(User $author, Article $article): Comment
    {
        $now = new DateTimeImmutable();
        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent('Commentaire signalé '.$this->uniqueToken('report-comment'))
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt($now)
            ->setPublishedAt($now);
        $this->entityManager->persist($comment);

        return $comment;
    }

    private function report(
        Comment $comment,
        User $reporter,
        CommentReportStatus $status,
        DateTimeImmutable $createdAt,
    ): CommentReport {
        $report = (new CommentReport())
            ->setComment($comment)
            ->setReporter($reporter)
            ->setReason(CommentReportReason::Spam)
            ->setStatus($status)
            ->setCreatedAt($createdAt);
        $this->entityManager->persist($reporter);
        $this->entityManager->persist($report);

        return $report;
    }

    private function repository(): CommentReportRepository
    {
        $repository = $this->entityManager->getRepository(CommentReport::class);
        self::assertInstanceOf(CommentReportRepository::class, $repository);

        return $repository;
    }
}
