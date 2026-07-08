<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\CommentMentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CommentMentionServiceTest extends TestCase
{
    public function testExtractHandlesLowercasesAndDeduplicatesMentions(): void
    {
        $service = $this->service([]);

        self::assertSame(
            ['alice', 'bob-test', 'marie.66'],
            $service->extractHandles('Merci @Alice, @bob-test, @alice et @Marie.66,'),
        );
    }

    public function testExtractHandlesIgnoresInvalidAndTooShortMentions(): void
    {
        $service = $this->service([]);

        self::assertSame(['valid_user'], $service->extractHandles('@a email@test.local fin@mot @valid_user'));
    }

    public function testExtractHandlesAcceptsMentionAtMaximumLength(): void
    {
        $service = $this->service([]);
        $handle = str_repeat('a', 80);

        self::assertSame([$handle], $service->extractHandles('@'.$handle));
    }

    public function testExtractHandlesRejectsOverlongMentionsWithoutExtractingTheirPrefix(): void
    {
        $service = $this->service([]);

        self::assertSame([], $service->extractHandles('@'.str_repeat('a', 81)));
        self::assertSame([], $service->extractHandles('@'.str_repeat('a', 120)));
    }

    public function testFindMentionedUsersDoesNotQueryRepositoryForOverlongMention(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository
            ->expects(self::never())
            ->method('findMentionableUsersByHandles');

        $service = new CommentMentionService($repository, $this->urlGenerator([]));

        self::assertSame([], $service->findMentionedUsers('@'.str_repeat('a', 81)));
    }

    public function testFindMentionedUsersDelegatesNormalizedHandlesToRepository(): void
    {
        $user = (new User())->setEmail('alice@example.test')->setDisplayName('Alice')->setPassword('x');
        $repository = $this->createMock(UserRepository::class);
        $repository
            ->expects(self::once())
            ->method('findMentionableUsersByHandles')
            ->with(['alice', 'bob'])
            ->willReturn([$user]);

        $service = new CommentMentionService($repository, $this->urlGenerator([]));

        self::assertSame([$user], $service->findMentionedUsers('@Alice @bob @alice'));
    }

    public function testRenderHtmlLinksKnownUsersAndEscapesContent(): void
    {
        $alice = (new User())->setEmail('alice@example.test')->setDisplayName('Alice')->setPassword('x');
        $this->setEntityId($alice, 42);

        $html = $this->service([$alice])->renderHtml('<b>Bonjour</b> @Alice @missing');

        self::assertSame(
            '&lt;b&gt;Bonjour&lt;/b&gt; <a class="comment-mention" href="/profile/42">@Alice</a> @missing',
            $html,
        );
    }

    public function testRenderHtmlKeepsOverlongAndHtmlAdjacentMentionsAsEscapedText(): void
    {
        $longHandleUser = (new User())
            ->setEmail('long@example.test')
            ->setDisplayName(str_repeat('a', 80))
            ->setPassword('x');
        $alice = (new User())->setEmail('alice@example.test')->setDisplayName('Alice')->setPassword('x');
        $this->setEntityId($longHandleUser, 42);
        $this->setEntityId($alice, 43);
        $content = '@'.str_repeat('a', 81).' @Alice<script>alert(1)</script>';

        self::assertSame(
            '@'.str_repeat('a', 81).' @Alice&lt;script&gt;alert(1)&lt;/script&gt;',
            $this->service([$longHandleUser, $alice])->renderHtml($content),
        );
    }

    public function testPunctuationTerminatesValidMention(): void
    {
        $alice = (new User())->setEmail('alice@example.test')->setDisplayName('Alice')->setPassword('x');
        $this->setEntityId($alice, 42);
        $service = $this->service([$alice]);

        foreach (['.', ',', '!'] as $punctuation) {
            self::assertSame(['alice'], $service->extractHandles('@Alice'.$punctuation));
            self::assertSame(
                '<a class="comment-mention" href="/profile/42">@Alice</a>'.$punctuation,
                $service->renderHtml('@Alice'.$punctuation),
            );
        }
    }

    /** @param list<User> $users */
    private function service(array $users): CommentMentionService
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

        return new CommentMentionService($repository, $this->urlGenerator([]));
    }

    /** @param array<string, string> $routes */
    private function urlGenerator(array $routes): UrlGeneratorInterface
    {
        return new class($routes) implements UrlGeneratorInterface {
            private RequestContext $context;

            /** @param array<string, string> $routes */
            public function __construct(private readonly array $routes)
            {
                $this->context = new RequestContext();
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return $this->routes[$name] ?? sprintf('/profile/%d', $parameters['id'] ?? 0);
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
