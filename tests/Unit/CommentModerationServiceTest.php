<?php

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\ModerationKeyword;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Enum\ModerationKeywordType;
use App\Repository\ModerationKeywordRepository;
use App\Service\CommentModerationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CommentModerationServiceTest extends TestCase
{
    public function testModerateNewApprovesNormalCommentAndIncrementsAuthorApprovalOnce(): void
    {
        $author = (new User())->setEmail('author@example.test');
        $comment = (new Comment())
            ->setAuthor($author)
            ->setContent('Merci pour cette belle découverte, le parcours donne vraiment envie.');
        $service = $this->service();

        $service->moderateNew($comment);
        $service->moderateNew($comment);

        self::assertSame(CommentStatus::Approved, $comment->getStatus());
        self::assertSame(0, $comment->getSpamScore());
        self::assertNull($comment->getModerationReason());
        self::assertNotNull($comment->getModeratedAt());
        self::assertNotNull($comment->getPublishedAt());
        self::assertNotNull($comment->getApprovedAt());
        self::assertSame(1, $author->getApprovedCommentsCount());
    }

    public function testModerateNewMarksBuiltInSpamKeywordAndExcessiveLinksAsSpam(): void
    {
        $comment = (new Comment())->setContent(
            'Casino crypto loan https://one.test https://two.test https://three.test https://four.test',
        );

        $this->service()->moderateNew($comment);

        self::assertSame(CommentStatus::Spam, $comment->getStatus());
        self::assertSame(100, $comment->getSpamScore());
        self::assertStringContainsString('Mot-cle suspect: casino.', (string) $comment->getModerationReason());
        self::assertStringContainsString('Nombre de liens eleve.', (string) $comment->getModerationReason());
        self::assertNull($comment->getPublishedAt());
        self::assertNull($comment->getApprovedAt());
    }

    public function testModerateNewUsesConfiguredReviewSpamAndBlockedKeywords(): void
    {
        $reviewComment = (new Comment())->setContent('Ce message mentionne review-token dans un texte normal.');
        $spamComment = (new Comment())->setContent('Ce message mentionne spam-token dans un texte normal.');
        $blockedComment = (new Comment())->setContent('Ce message mentionne blocked-token dans un texte normal.');
        $service = $this->service([
            $this->keyword('review-token', ModerationKeywordType::Review),
            $this->keyword('spam-token', ModerationKeywordType::Spam),
            $this->keyword('blocked-token', ModerationKeywordType::Blocked),
            $this->keyword('', ModerationKeywordType::Blocked),
        ]);

        $service->moderateNew($reviewComment);
        $service->moderateNew($spamComment);
        $service->moderateNew($blockedComment);

        self::assertSame(CommentStatus::Approved, $reviewComment->getStatus());
        self::assertSame(25, $reviewComment->getSpamScore());
        self::assertStringContainsString('Mot-cle de moderation review: review-token.', (string) $reviewComment->getModerationReason());
        self::assertSame(CommentStatus::Spam, $spamComment->getStatus());
        self::assertSame(70, $spamComment->getSpamScore());
        self::assertSame(CommentStatus::Spam, $blockedComment->getStatus());
        self::assertSame(100, $blockedComment->getSpamScore());
    }

    public function testAdminEditedSpamIsApprovedButRegularEditedSpamIsRejected(): void
    {
        $admin = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $regularEditor = (new User())->setEmail('user@example.test');
        $adminEdited = (new Comment())->setContent('casino');
        $regularEdited = (new Comment())->setContent('casino');
        $service = $this->service();

        $service->moderateEdited($adminEdited, $admin, isAdmin: true, previousStatus: CommentStatus::Approved);
        $service->moderateEdited($regularEdited, $regularEditor, isAdmin: false, previousStatus: CommentStatus::Approved);

        self::assertSame(CommentStatus::Approved, $adminEdited->getStatus());
        self::assertSame(65, $adminEdited->getSpamScore());
        self::assertSame(CommentStatus::Approved, $regularEdited->getStatus());

        $regularEdited->setContent('casino crypto loan');
        $service->moderateEdited($regularEdited, $regularEditor, isAdmin: false, previousStatus: CommentStatus::Approved);

        self::assertSame(CommentStatus::Spam, $regularEdited->getStatus());
        self::assertSame(100, $regularEdited->getSpamScore());
    }

    public function testEmptyAndShortContentReceiveExpectedModerationScores(): void
    {
        $empty = (new Comment())->setContent('   ');
        $short = (new Comment())->setContent('Merci');

        $this->service()->moderateNew($empty);
        $this->service()->moderateNew($short);

        self::assertSame(CommentStatus::Spam, $empty->getStatus());
        self::assertSame(80, $empty->getSpamScore());
        self::assertStringContainsString('Contenu vide.', (string) $empty->getModerationReason());
        self::assertSame(CommentStatus::Approved, $short->getStatus());
        self::assertSame(30, $short->getSpamScore());
        self::assertStringContainsString('Contenu trop court.', (string) $short->getModerationReason());
    }

    public function testReportThresholdOnlyHidesApprovedCommentsAtThreshold(): void
    {
        $belowThreshold = (new Comment())
            ->setStatus(CommentStatus::Approved)
            ->setReportedCount(2)
            ->setContent('Commentaire public normal.');
        $alreadySpam = (new Comment())
            ->setStatus(CommentStatus::Spam)
            ->setReportedCount(3)
            ->setContent('Commentaire déjà spam.');
        $approvedAtThreshold = (new Comment())
            ->setStatus(CommentStatus::Approved)
            ->setReportedCount(3)
            ->setContent('Commentaire signalé.');
        $service = $this->service(reportThreshold: 3);

        $service->applyReportThreshold($belowThreshold);
        $service->applyReportThreshold($alreadySpam);
        $service->applyReportThreshold($approvedAtThreshold);

        self::assertSame(CommentStatus::Approved, $belowThreshold->getStatus());
        self::assertSame(CommentStatus::Spam, $alreadySpam->getStatus());
        self::assertSame(CommentStatus::HiddenPendingReport, $approvedAtThreshold->getStatus());
        self::assertSame('Signalement en attente de modération.', $approvedAtThreshold->getModerationReason());
    }

    public function testHideForPendingReportReviewIgnoresNonApprovedComments(): void
    {
        $spam = (new Comment())
            ->setStatus(CommentStatus::Spam)
            ->setContent('Commentaire déjà traité.')
            ->setModerationReason('Spam existant.')
            ->setModeratedAt(new DateTimeImmutable('-1 day'));

        $this->service()->hideForPendingReportReview($spam);

        self::assertSame(CommentStatus::Spam, $spam->getStatus());
        self::assertSame('Spam existant.', $spam->getModerationReason());
    }

    /**
     * @param list<ModerationKeyword> $keywords
     */
    private function service(array $keywords = [], int $reportThreshold = 3): CommentModerationService
    {
        $repository = $this->createStub(ModerationKeywordRepository::class);
        $repository->method('findEnabledKeywords')->willReturn($keywords);

        return new CommentModerationService($repository, $reportThreshold);
    }

    private function keyword(string $keyword, ModerationKeywordType $type): ModerationKeyword
    {
        return (new ModerationKeyword())
            ->setKeyword($keyword)
            ->setType($type)
            ->setEnabled(true);
    }
}
