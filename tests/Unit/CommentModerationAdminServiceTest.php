<?php

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\User;
use App\Entity\UserModerationWarning;
use App\Enum\CommentStatus;
use App\Service\CommentModerationAdminService;
use App\Service\CommentModerationMailer;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class CommentModerationAdminServiceTest extends TestCase
{
    public function testApproveAndRestorePublishCommentAndIncrementAuthorApprovalOnce(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $author = $this->user('author@example.test');
        $comment = (new Comment())
            ->setAuthor($author)
            ->setStatus(CommentStatus::Pending)
            ->setContent('Commentaire en attente.');
        [$service] = $this->service();

        $service->approve($comment, $admin);
        $firstApprovedAt = $comment->getApprovedAt();
        $service->approve($comment, $admin);

        self::assertSame(CommentStatus::Approved, $comment->getStatus());
        self::assertNull($comment->getModerationReason());
        self::assertSame($admin, $comment->getModeratedBy());
        self::assertNotNull($comment->getPublishedAt());
        self::assertSame($firstApprovedAt, $comment->getApprovedAt());
        self::assertSame(1, $author->getApprovedCommentsCount());

        $restored = (new Comment())
            ->setAuthor($author)
            ->setStatus(CommentStatus::HiddenByAdmin)
            ->setModerationReason('Masqué.')
            ->setContent('Commentaire restauré.');

        $service->restore($restored, $admin);

        self::assertSame(CommentStatus::Approved, $restored->getStatus());
        self::assertNull($restored->getModerationReason());
        self::assertSame(2, $author->getApprovedCommentsCount());
    }

    public function testRejectRequiresReason(): void
    {
        [$service] = $this->service();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La justification du refus est obligatoire.');

        $service->reject(new Comment(), $this->user('admin@example.test', ['ROLE_ADMIN']), '   ');
    }

    public function testRejectWarnsAuthorSendsEmailAndAutoBansAfterThirdRejectedComment(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $author = $this->user('author@example.test')->setRejectedCommentsCount(2);
        $comment = (new Comment())
            ->setAuthor($author)
            ->setStatus(CommentStatus::Pending)
            ->setContent('Commentaire problématique.');
        [$service, $persistedWarnings, $mailer] = $this->service();

        $service->reject($comment, $admin, 'Hors charte.');

        self::assertSame('Commentaire refusé par la modération.', $comment->getContent());
        self::assertSame(CommentStatus::Rejected, $comment->getStatus());
        self::assertSame('Hors charte.', $comment->getModerationReason());
        self::assertSame($admin, $comment->getModeratedBy());
        self::assertSame(3, $author->getRejectedCommentsCount());
        self::assertTrue($author->isBanned());
        self::assertSame('Bannissement automatique après 3 commentaires refusés.', $author->getBanReason());
        self::assertCount(1, $persistedWarnings->warnings);
        self::assertSame($author, $persistedWarnings->warnings[0]->getUser());
        self::assertSame($comment, $persistedWarnings->warnings[0]->getComment());
        self::assertCount(1, $mailer->messages);
        self::assertInstanceOf(TemplatedEmail::class, $mailer->messages[0]);
        $message = $mailer->messages[0];
        self::assertSame('emails/comment_rejected.html.twig', $message->getHtmlTemplate());
        self::assertTrue($message->getContext()['auto_banned']);
        self::assertSame(3, $message->getContext()['rejected_count']);
    }

    public function testRejectingAlreadyRejectedCommentDoesNotWarnOrIncrementAgain(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $author = $this->user('author@example.test')->setRejectedCommentsCount(2);
        $comment = (new Comment())
            ->setAuthor($author)
            ->setStatus(CommentStatus::Rejected)
            ->setContent('Déjà refusé.');
        [$service, $persistedWarnings, $mailer] = $this->service();

        $service->reject($comment, $admin, 'Toujours hors charte.');

        self::assertSame(2, $author->getRejectedCommentsCount());
        self::assertFalse($author->isBanned());
        self::assertSame([], $persistedWarnings->warnings);
        self::assertCount(1, $mailer->messages);
        self::assertInstanceOf(TemplatedEmail::class, $mailer->messages[0]);
        self::assertFalse($mailer->messages[0]->getContext()['auto_banned']);
    }

    public function testSpamHideSoftDeleteBanAndUnbanActions(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $author = $this->user('author@example.test');
        $spam = (new Comment())->setAuthor($author)->setContent('Spam.');
        $hidden = (new Comment())->setAuthor($author)->setContent('A masquer.');
        $deleted = (new Comment())->setAuthor($author)->setContent('A supprimer.');
        [$service, $persistedWarnings] = $this->service();

        $service->markAsSpam($spam, $admin);
        $service->hide($hidden, $admin, ' ');
        $service->softDelete($deleted, $admin, 'Suppression demandée.');
        $service->banUser($author, 'Décision admin.');

        self::assertSame(CommentStatus::Spam, $spam->getStatus());
        self::assertSame('Commentaire marqué comme spam.', $spam->getModerationReason());
        self::assertSame(0, $author->getRejectedCommentsCount());
        self::assertCount(1, $persistedWarnings->warnings);
        self::assertSame(CommentStatus::HiddenByAdmin, $hidden->getStatus());
        self::assertSame('Commentaire masqué par la modération.', $hidden->getModerationReason());
        self::assertSame(CommentStatus::Deleted, $deleted->getStatus());
        self::assertSame('Commentaire supprimé par la modération.', $deleted->getContent());
        self::assertSame('Suppression demandée.', $deleted->getModerationReason());
        self::assertTrue($author->isBanned());
        self::assertSame('Décision admin.', $author->getBanReason());

        $service->unbanUser($author);

        self::assertFalse($author->isBanned());
        self::assertNull($author->getBannedAt());
        self::assertNull($author->getBanReason());
    }

    /**
     * @return array{0: CommentModerationAdminService, 1: PersistedWarningCollector, 2: CollectingMailer}
     */
    private function service(): array
    {
        $persistedWarnings = new PersistedWarningCollector();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use ($persistedWarnings): void {
                if ($entity instanceof UserModerationWarning) {
                    $persistedWarnings->warnings[] = $entity;
                }
            },
        );

        $mailer = new CollectingMailer();
        $service = new CommentModerationAdminService(
            $entityManager,
            new CommentModerationMailer($mailer, 'noreply@example.test'),
        );

        return [$service, $persistedWarnings, $mailer];
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $email, array $roles = []): User
    {
        return (new User())
            ->setEmail($email)
            ->setRoles($roles);
    }
}

final class CollectingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }
}

final class PersistedWarningCollector
{
    /** @var list<UserModerationWarning> */
    public array $warnings = [];
}
