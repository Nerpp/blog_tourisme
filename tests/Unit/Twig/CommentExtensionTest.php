<?php

namespace App\Tests\Unit\Twig;

use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\CommentReplyNotificationRepository;
use App\Repository\UserRepository;
use App\Service\CommentMentionService;
use App\Twig\CommentExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class CommentExtensionTest extends TestCase
{
    public function testDeclaresCommentTwigFunctions(): void
    {
        $names = array_map(
            static fn ($function): string => $function->getName(),
            $this->extension()->getFunctions(),
        );

        self::assertSame(
            ['comment_content_html', 'comment_unread_notification_count', 'comment_pending_report_count'],
            $names,
        );
    }

    public function testContentHtmlRendersMentionsAndEscapesContent(): void
    {
        $alice = (new User())->setEmail('alice@example.test')->setDisplayName('Alice')->setPassword('x');
        $this->setEntityId($alice, 42);

        self::assertSame(
            '&lt;b&gt;Bonjour&lt;/b&gt; <a class="comment-mention" href="/profile/42">@Alice</a><br>'."\n".'ligne',
            $this->extension(mentionService: $this->mentionService([$alice]))->contentHtml('<b>Bonjour</b> @Alice'."\n".'ligne'),
        );
    }

    public function testContentHtmlCastsNullToEmptyString(): void
    {
        self::assertSame('', $this->extension()->contentHtml(null));
    }

    public function testUnreadNotificationCountRequiresUser(): void
    {
        $user = (new User())->setEmail('reader@example.test')->setPassword('x');
        $notifications = $this->createMock(CommentReplyNotificationRepository::class);
        $notifications
            ->expects(self::once())
            ->method('countUnreadForRecipient')
            ->with($user)
            ->willReturn(7);

        $extension = $this->extension(notificationRepository: $notifications);

        self::assertSame(0, $extension->unreadNotificationCount(null));
        self::assertSame(0, $extension->unreadNotificationCount('reader'));
        self::assertSame(7, $extension->unreadNotificationCount($user));
    }

    public function testPendingReportCountDelegatesToRepository(): void
    {
        $comments = $this->createMock(CommentRepository::class);
        $comments
            ->expects(self::once())
            ->method('countReportedForModeration')
            ->willReturn(4);

        self::assertSame(4, $this->extension(commentRepository: $comments)->pendingReportCount());
    }

    private function extension(
        ?CommentMentionService $mentionService = null,
        ?CommentReplyNotificationRepository $notificationRepository = null,
        ?CommentRepository $commentRepository = null,
    ): CommentExtension {
        $mentionService ??= $this->mentionService([]);

        $notificationRepository ??= $this->createStub(CommentReplyNotificationRepository::class);
        $notificationRepository->method('countUnreadForRecipient')->willReturn(0);

        $commentRepository ??= $this->createStub(CommentRepository::class);
        $commentRepository->method('countReportedForModeration')->willReturn(0);

        return new CommentExtension($mentionService, $notificationRepository, $commentRepository);
    }

    /** @param list<User> $users */
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

            /** @param array<string, mixed> $parameters */
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

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
