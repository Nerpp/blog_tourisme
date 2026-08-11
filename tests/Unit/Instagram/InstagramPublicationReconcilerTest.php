<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\InstagramPublication;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Repository\InstagramPublicationRepository;
use App\Service\Instagram\InstagramCaptionBuilder;
use App\Service\Instagram\InstagramMediaBatcher;
use App\Service\Instagram\InstagramMediaCollector;
use App\Service\Instagram\InstagramPublicationReconciler;
use App\Service\Instagram\InstagramPublicationScheduler;
use App\Service\Instagram\MediaPublicUrlResolver;
use App\Service\Seo\PublicUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;

final class InstagramPublicationReconcilerTest extends TestCase
{
    public function testItRedispatchesTheSamePendingPublicationOnlyOnce(): void
    {
        $now = new \DateTimeImmutable();
        $publication = $this->publication(401, InstagramPublicationStatus::Pending)
            ->setLastDispatchedAt($now->modify('-1 hour'));
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findRecoverableForDispatch')
            ->with(
                $now->modify('-5 minutes'),
                $now->modify('-30 minutes'),
                50,
            )
            ->willReturn([$publication, $publication]);
        $repository->expects(self::exactly(2))
            ->method('findOneForUpdate')
            ->with(401)
            ->willReturn($publication);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::exactly(2))->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $reconciler = new InstagramPublicationReconciler(
            $repository,
            $this->scheduler($entityManager, $repository, $messageBus),
            $this->lockFactory(expectedAcquisitions: 2, expectedRefreshes: 2),
        );

        $firstResult = $reconciler->reconcile($now);
        $secondResult = $reconciler->reconcile($now);

        self::assertSame(2, $firstResult->candidateCount);
        self::assertSame(1, $firstResult->dispatchedCount);
        self::assertSame(2, $secondResult->candidateCount);
        self::assertSame(0, $secondResult->dispatchedCount);
        self::assertNotNull($publication->getLastDispatchedAt());
    }

    public function testItIgnoresPublishedLegacyAndFailedPublications(): void
    {
        $published = $this->publication(402, InstagramPublicationStatus::Published);
        $legacy = $this->publication(403, InstagramPublicationStatus::LegacySkipped);
        $failed = $this->publication(404, InstagramPublicationStatus::Failed);
        $publicationsById = [
            402 => $published,
            403 => $legacy,
            404 => $failed,
        ];
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findRecoverableForDispatch')
            ->willReturn([$published, $legacy, $failed]);
        $repository->expects(self::exactly(3))
            ->method('findOneForUpdate')
            ->willReturnCallback(static fn (int $id): InstagramPublication => $publicationsById[$id]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(3))
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::never())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');
        $reconciler = new InstagramPublicationReconciler(
            $repository,
            $this->scheduler($entityManager, $repository, $messageBus),
            $this->lockFactory(expectedRefreshes: 3),
        );

        $result = $reconciler->reconcile(new \DateTimeImmutable());

        self::assertSame(3, $result->candidateCount);
        self::assertSame(0, $result->dispatchedCount);
        self::assertFalse($result->skippedDueToLock);
    }

    public function testItSkipsWithoutQueryingWhenTheWebCronLockIsAlreadyHeld(): void
    {
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::never())->method('findRecoverableForDispatch');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');
        $reconciler = new InstagramPublicationReconciler(
            $repository,
            $this->scheduler($this->createStub(EntityManagerInterface::class), $repository, $messageBus),
            $this->lockFactory(acquired: false),
        );

        $result = $reconciler->reconcile(new \DateTimeImmutable());

        self::assertSame(0, $result->candidateCount);
        self::assertSame(0, $result->dispatchedCount);
        self::assertTrue($result->skippedDueToLock);
    }

    public function testItReleasesTheLockWhenReconciliationThrows(): void
    {
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findRecoverableForDispatch')
            ->willThrowException(new \RuntimeException('query failed'));
        $reconciler = new InstagramPublicationReconciler(
            $repository,
            $this->scheduler(
                $this->createStub(EntityManagerInterface::class),
                $repository,
                $this->createStub(MessageBusInterface::class),
            ),
            $this->lockFactory(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('query failed');

        $reconciler->reconcile(new \DateTimeImmutable());
    }

    private function publication(int $id, InstagramPublicationStatus $status): InstagramPublication
    {
        $publication = (new InstagramPublication(InstagramPublicationSourceType::Hike, $id, 'Légende'))
            ->setStatus($status)
            ->setTotalBatchCount(1);
        (new \ReflectionProperty($publication, 'id'))->setValue($publication, $id);

        return $publication;
    }

    private function scheduler(
        EntityManagerInterface $entityManager,
        InstagramPublicationRepository $repository,
        MessageBusInterface $messageBus,
    ): InstagramPublicationScheduler {
        $publicUrlGenerator = new PublicUrlGenerator(
            $this->createStub(RouterInterface::class),
            'https://estela-exploration.fr',
        );

        return new InstagramPublicationScheduler(
            $entityManager,
            $repository,
            new InstagramMediaCollector(new MediaPublicUrlResolver($publicUrlGenerator)),
            new InstagramMediaBatcher(),
            new InstagramCaptionBuilder(),
            $messageBus,
            new NullLogger(),
        );
    }

    private function lockFactory(
        bool $acquired = true,
        int $expectedAcquisitions = 1,
        int $expectedRefreshes = 0,
    ): LockFactory
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::exactly($expectedAcquisitions))
            ->method('acquire')
            ->with(false)
            ->willReturn($acquired);
        $releaseExpectation = $acquired
            ? $lock->expects(self::exactly($expectedAcquisitions))
            : $lock->expects(self::never());
        $releaseExpectation->method('release');
        $lock->expects(self::exactly($expectedRefreshes))
            ->method('refresh')
            ->with(InstagramPublicationReconciler::LOCK_TTL_SECONDS);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::exactly($expectedAcquisitions))
            ->method('createLock')
            ->with(
                InstagramPublicationReconciler::LOCK_RESOURCE,
                InstagramPublicationReconciler::LOCK_TTL_SECONDS,
            )
            ->willReturn($lock);

        return $lockFactory;
    }
}
