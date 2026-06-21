<?php

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\CommentReplyNotification;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Repository\CommentReplyNotificationRepository;
use App\Repository\UserRepository;
use App\Service\CommentMentionService;
use App\Service\CommentReplyNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class CommentReplyNotificationServiceTest extends TestCase
{
    public function testApprovedCommentMentionDoesNotNotifyAuthorAboutTheirOwnMention(): void
    {
        $author = $this->user('author@example.test', 'Author Name', 10);
        $mentionedUser = $this->user('mentioned@example.test', 'Mentioned User', 20);
        $comment = (new Comment())
            ->setAuthor($author)
            ->setStatus(CommentStatus::Approved)
            ->setContent(sprintf('Merci @%s et @%s pour vos idees.', $author->getMentionHandle(), $mentionedUser->getMentionHandle()));

        $repository = $this->createMock(CommentReplyNotificationRepository::class);
        $repository
            ->expects(self::never())
            ->method('findOneByRecipientAndComment');

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (CommentReplyNotification $notification) use (&$persisted): void {
                $persisted[] = $notification;
            });

        (new CommentReplyNotificationService(
            $this->mentionService([$author, $mentionedUser]),
            $repository,
            $entityManager,
        ))->createForApprovedComment($comment);

        self::assertCount(1, $persisted);
        self::assertSame($mentionedUser, $persisted[0]->getRecipient());
        self::assertSame($author, $persisted[0]->getTriggeredBy());
        self::assertSame($comment, $persisted[0]->getComment());
        self::assertSame(CommentReplyNotification::KIND_MENTION, $persisted[0]->getKind());
    }

    public function testApprovedCommentDoesNotNotifyUserMatchingPrefixOfOverlongMention(): void
    {
        $author = $this->user('author@example.test', 'Author Name', 10);
        $mentionedUser = $this->user('mentioned@example.test', str_repeat('a', 80), 20);
        $comment = (new Comment())
            ->setAuthor($author)
            ->setStatus(CommentStatus::Approved)
            ->setContent('@'.str_repeat('a', 81));

        $repository = $this->createMock(CommentReplyNotificationRepository::class);
        $repository
            ->expects(self::never())
            ->method('findOneByRecipientAndComment');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::never())
            ->method('persist');

        (new CommentReplyNotificationService(
            $this->mentionService([$mentionedUser]),
            $repository,
            $entityManager,
        ))->createForApprovedComment($comment);
    }

    /**
     * @param list<User> $users
     */
    private function mentionService(array $users): CommentMentionService
    {
        $repository = $this->createStub(UserRepository::class);
        $repository
            ->method('findMentionableUsersByHandles')
            ->willReturnCallback(
                static fn (array $handles): array => array_values(array_filter(
                    $users,
                    static fn (User $user): bool => in_array($user->getMentionHandle(), $handles, true),
                )),
            );

        return new CommentMentionService($repository, $this->urlGenerator());
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return sprintf('/profile/%d', $parameters['id'] ?? 0);
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }

    private function user(string $email, string $displayName, int $id): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($displayName)
            ->setPassword('test-password');

        $property = new \ReflectionProperty($user, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
