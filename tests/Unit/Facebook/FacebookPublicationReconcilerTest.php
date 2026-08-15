<?php

namespace App\Tests\Unit\Facebook;

use App\Entity\FacebookPublication;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Repository\FacebookPublicationRepository;
use App\Service\Facebook\FacebookPublicationReconciler;
use App\Service\Facebook\FacebookPublicationScheduler;
use App\Service\Seo\PublicUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;

final class FacebookPublicationReconcilerTest extends TestCase
{
    public function testItRedispatchesAnOldPendingPublicationOnlyOnce(): void
    {
        $now = new \DateTimeImmutable('2026-08-15T12:00:00+02:00');
        $publication = $this->publication(701, $now->modify('-1 hour'));
        $repository = $this->createMock(FacebookPublicationRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findPendingForDispatch')
            ->with($now->modify('-5 minutes'), FacebookPublicationReconciler::DEFAULT_LIMIT)
            ->willReturn([$publication, $publication]);
        $repository->expects(self::exactly(2))
            ->method('findOneForUpdate')
            ->with(701)
            ->willReturn($publication);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::once())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $reconciler = new FacebookPublicationReconciler(
            $repository,
            $this->scheduler($entityManager, $repository, $messageBus),
        );

        $first = $reconciler->reconcile($now);
        $second = $reconciler->reconcile($now);

        self::assertSame(2, $first->candidateCount);
        self::assertSame(1, $first->dispatchedCount);
        self::assertSame(2, $second->candidateCount);
        self::assertSame(0, $second->dispatchedCount);
        self::assertNotNull($publication->getLastDispatchedAt());
    }

    public function testItDefensivelyRefusesFailedPublications(): void
    {
        $publication = $this->publication(702, new \DateTimeImmutable('-1 hour'))
            ->markFailed('Échec terminal de test.', 'test_failed');
        $repository = $this->createMock(FacebookPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findPendingForDispatch')
            ->willReturn([$publication]);
        $repository->expects(self::once())
            ->method('findOneForUpdate')
            ->with(702)
            ->willReturn($publication);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::never())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $result = (new FacebookPublicationReconciler(
            $repository,
            $this->scheduler($entityManager, $repository, $messageBus),
        ))->reconcile(new \DateTimeImmutable());

        self::assertSame(FacebookPublicationStatus::Failed, $publication->getStatus());
        self::assertSame(1, $result->candidateCount);
        self::assertSame(0, $result->dispatchedCount);
    }

    public function testItRechecksTheAgeOfANeverDispatchedPublicationUnderLock(): void
    {
        $now = new \DateTimeImmutable('2026-08-15T12:00:00+02:00');
        $publication = $this->publication(703, $now);
        $repository = $this->createMock(FacebookPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findPendingForDispatch')
            ->willReturn([$publication]);
        $repository->expects(self::once())
            ->method('findOneForUpdate')
            ->with(703)
            ->willReturn($publication);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::never())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $result = (new FacebookPublicationReconciler(
            $repository,
            $this->scheduler($entityManager, $repository, $messageBus),
        ))->reconcile($now);

        self::assertSame(1, $result->candidateCount);
        self::assertSame(0, $result->dispatchedCount);
        self::assertNull($publication->getLastDispatchedAt());
    }

    public function testItRejectsUnsafeLimits(): void
    {
        $reconciler = new FacebookPublicationReconciler(
            $this->createStub(FacebookPublicationRepository::class),
            $this->scheduler(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(FacebookPublicationRepository::class),
                $this->createStub(MessageBusInterface::class),
            ),
        );

        $this->expectException(\InvalidArgumentException::class);
        $reconciler->reconcile(new \DateTimeImmutable(), pendingAgeSeconds: 0);
    }

    private function publication(int $id, \DateTimeImmutable $createdAt): FacebookPublication
    {
        $publication = new FacebookPublication(
            FacebookPublicationSourceType::Hike,
            $id,
            'Une nouvelle randonnée.',
            'https://estela-exploration.fr/randonnees/reconciliation',
            $createdAt,
        );
        (new \ReflectionProperty($publication, 'id'))->setValue($publication, $id);

        return $publication;
    }

    private function scheduler(
        EntityManagerInterface $entityManager,
        FacebookPublicationRepository $repository,
        MessageBusInterface $messageBus,
    ): FacebookPublicationScheduler {
        return new FacebookPublicationScheduler(
            $entityManager,
            $repository,
            new PublicUrlGenerator(
                $this->createStub(RouterInterface::class),
                'https://estela-exploration.fr',
            ),
            $messageBus,
            new NullLogger(),
        );
    }
}
