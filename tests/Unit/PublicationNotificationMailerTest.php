<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\PublicationNotificationLog;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Repository\PublicationNotificationLogRepository;
use App\Repository\UserRepository;
use App\Service\PublicationNotificationMailer;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class PublicationNotificationMailerTest extends TestCase
{
    public function testUnsupportedOrNonPublicContentIsSkipped(): void
    {
        $mailer = $this->mailer();

        self::assertSame(
            ['recipientCount' => 0, 'sentCount' => 0, 'errorCount' => 0, 'skipped' => true, 'reason' => 'unsupported_content'],
            $mailer->sendNewPublicationNotification(new \stdClass()),
        );

        $draft = (new Article())
            ->setTitle('Brouillon')
            ->setSlug('brouillon')
            ->setStatus(ContentStatus::Draft);

        self::assertSame(
            ['recipientCount' => 0, 'sentCount' => 0, 'errorCount' => 0, 'skipped' => true, 'reason' => 'content_not_public'],
            $mailer->sendNewPublicationNotification($draft),
        );
    }

    public function testPublishedContentWithoutIdIsSkipped(): void
    {
        $article = $this->publishedArticle('nouveaute');

        self::assertSame(
            ['recipientCount' => 0, 'sentCount' => 0, 'errorCount' => 0, 'skipped' => true, 'reason' => 'missing_content_id'],
            $this->mailer()->sendNewPublicationNotification($article),
        );
    }

    public function testAlreadySentContentIsSkippedBeforeLoadingRecipients(): void
    {
        $article = $this->publishedArticle('deja-envoye', 42);

        $logs = $this->createMock(PublicationNotificationLogRepository::class);
        $logs
            ->expects(self::once())
            ->method('hasNotificationBeenSent')
            ->with('article', 42)
            ->willReturn(true);

        $users = $this->createMock(UserRepository::class);
        $users
            ->expects(self::never())
            ->method('findUsersSubscribedToPublicationEmails');

        self::assertSame(
            ['recipientCount' => 0, 'sentCount' => 0, 'errorCount' => 0, 'skipped' => true, 'reason' => 'already_sent'],
            $this->mailer(userRepository: $users, notificationLogRepository: $logs)->sendNewPublicationNotification($article),
        );
    }

    public function testSendsNotificationToValidSubscribedRecipientsOnly(): void
    {
        $article = $this->publishedArticle('article-public', 77);
        $validUser = $this->user('reader@example.test', 5);
        $invalidUser = $this->user('not-an-email', 6);

        $users = $this->createStub(UserRepository::class);
        $users->method('findUsersSubscribedToPublicationEmails')->willReturn([$validUser, $invalidUser]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (PublicationNotificationLog $log): bool {
                return $log->getContentType() === 'article'
                    && $log->getContentId() === 77
                    && $log->getRecipientCount() === 1;
            }));
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $transport = $this->createMock(MailerInterface::class);
        $transport
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email): bool {
                return $email->getSubject() === 'Nouvelle publication sur Estela Explorations'
                    && $email->getHtmlTemplate() === 'emails/new_publication.html.twig'
                    && ($email->getContext()['content_title'] ?? null) === 'Article public'
                    && ($email->getContext()['publication_url'] ?? null) === 'https://example.test/articles/article-public'
                    && ($email->getContext()['profile_url'] ?? null) === 'https://example.test/profil'
                    && ($email->getTo()[0]->getAddress() ?? null) === 'reader@example.test';
            }));

        self::assertSame(
            ['recipientCount' => 1, 'sentCount' => 1, 'errorCount' => 0, 'skipped' => false, 'reason' => null],
            $this->mailer(mailer: $transport, userRepository: $users, entityManager: $entityManager)
                ->sendNewPublicationNotification($article),
        );
    }

    public function testUniqueLogRaceIsReportedAsAlreadySent(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('flush')
            ->willThrowException(new UniqueConstraintViolationException($this->createStub(DriverExceptionInterface::class), null));

        self::assertSame(
            ['recipientCount' => 0, 'sentCount' => 0, 'errorCount' => 0, 'skipped' => true, 'reason' => 'already_sent'],
            $this->mailer(entityManager: $entityManager)->sendNewPublicationNotification($this->publishedArticle('race', 9)),
        );
    }

    public function testFlushFailureAndRecipientFailureAreReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::exactly(2))
            ->method('error');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('flush')
            ->willThrowException(new RuntimeException('database down'));

        self::assertSame(
            ['recipientCount' => 0, 'sentCount' => 0, 'errorCount' => 1, 'skipped' => false, 'reason' => 'log_failed'],
            $this->mailer(entityManager: $entityManager, logger: $logger)->sendNewPublicationNotification($this->publishedArticle('flush-fail', 10)),
        );

        $users = $this->createStub(UserRepository::class);
        $users->method('findUsersSubscribedToPublicationEmails')->willReturn([$this->user('reader@example.test', 8)]);

        $failingTransport = $this->createMock(MailerInterface::class);
        $failingTransport
            ->expects(self::once())
            ->method('send')
            ->willThrowException(new TransportException('smtp down'));

        self::assertSame(
            ['recipientCount' => 1, 'sentCount' => 0, 'errorCount' => 1, 'skipped' => false, 'reason' => null],
            $this->mailer(mailer: $failingTransport, userRepository: $users, logger: $logger)
                ->sendNewPublicationNotification($this->publishedArticle('mail-fail', 11)),
        );
    }

    private function mailer(
        ?MailerInterface $mailer = null,
        ?UserRepository $userRepository = null,
        ?PublicationNotificationLogRepository $notificationLogRepository = null,
        ?EntityManagerInterface $entityManager = null,
        ?LoggerInterface $logger = null,
    ): PublicationNotificationMailer {
        $mailer ??= $this->createStub(MailerInterface::class);

        $userRepository ??= $this->createStub(UserRepository::class);
        $userRepository->method('findUsersSubscribedToPublicationEmails')->willReturn([]);

        $notificationLogRepository ??= $this->createStub(PublicationNotificationLogRepository::class);
        $notificationLogRepository->method('hasNotificationBeenSent')->willReturn(false);

        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $logger ??= $this->createStub(LoggerInterface::class);

        return new PublicationNotificationMailer(
            $mailer,
            $userRepository,
            $notificationLogRepository,
            $entityManager,
            $this->urlGenerator(),
            $logger,
            'no-reply@example.test',
        );
    }

    private function publishedArticle(string $slug, ?int $id = null): Article
    {
        $article = (new Article())
            ->setTitle('Article public')
            ->setSlug($slug)
            ->setExcerpt('<p>Résumé avec   espaces</p>')
            ->setContent('<p>Contenu</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 hour'));

        if ($id !== null) {
            $this->setEntityId($article, $id);
        }

        return $article;
    }

    private function user(string $email, int $id): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setReceivePublicationEmails(true)
            ->setIsVerified(true);
        $this->setEntityId($user, $id);

        return $user;
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
                if ($name === 'app_profile') {
                    return 'https://example.test/profil';
                }

                return sprintf('https://example.test/articles/%s', $parameters['slug'] ?? '');
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
