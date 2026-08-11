<?php

namespace App\Tests\Unit\Instagram;

use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\InstagramPublicationBatchStatus;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Repository\InstagramPublicationRepository;
use App\Service\Instagram\InstagramCaptionBuilder;
use App\Service\Instagram\InstagramMediaBatcher;
use App\Service\Instagram\InstagramMediaCollector;
use App\Service\Instagram\InstagramPublicationScheduler;
use App\Service\Instagram\MediaPublicUrlResolver;
use App\Service\Seo\PublicUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;

final class InstagramPublicationSchedulerTest extends TestCase
{
    public function testItSnapshotsElevenMediaInTwoOrderedBatchesAndPinsTheirAssets(): void
    {
        [$hike, $assets] = $this->hikeWithMedia(101, 11);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(InstagramPublication::class));
        $repository = $this->repositoryWithoutExistingPublication(InstagramPublicationSourceType::Hike, 101);

        $publication = $this->scheduler($entityManager, $repository)->prepareFirstPublication($hike);

        self::assertInstanceOf(InstagramPublication::class, $publication);
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertSame(11, $publication->getTotalMediaCount());
        self::assertSame(2, $publication->getTotalBatchCount());
        self::assertSame(0, $publication->getPublishedMediaCount());
        self::assertSame(0, $publication->getPublishedBatchCount());

        $batches = array_values($publication->getBatches()->toArray());
        self::assertCount(2, $batches);
        self::assertSame([1, 2], array_map(
            static fn (InstagramPublicationBatch $batch): int => $batch->getPosition(),
            $batches,
        ));
        self::assertSame([10, 1], array_map(
            static fn (InstagramPublicationBatch $batch): int => $batch->getMediaCount(),
            $batches,
        ));
        self::assertStringContainsString('Publication 1/2', (string) $batches[0]->getCaption());
        self::assertStringContainsString('Publication 2/2', (string) $batches[1]->getCaption());

        $snapshotMedia = array_values($publication->getMedia()->toArray());
        self::assertCount(11, $snapshotMedia);
        self::assertSame(range(1, 11), array_map(
            static fn (InstagramPublicationMedia $media): int => $media->getPosition(),
            $snapshotMedia,
        ));
        self::assertSame([...range(1, 10), 1], array_map(
            static fn (InstagramPublicationMedia $media): int => $media->getBatchPosition(),
            $snapshotMedia,
        ));

        foreach ($snapshotMedia as $index => $media) {
            self::assertSame($assets[$index], $media->getMediaAsset());
            self::assertSame($index + 1, $media->getSourceMediaId());
            self::assertSame(
                sprintf('https://estela-exploration.fr/uploads/media/photo-%d.webp', $index + 1),
                $media->getPublicUrl(),
            );
            self::assertSame($media->getBatch()?->getCaption(), $media->getCaption());
            self::assertTrue($assets[$index]->getInstagramPublicationMedia()->contains($media));
        }
    }

    public function testItPersistsNoMediaAsAnExplicitTerminalStateWithoutDispatching(): void
    {
        $hike = (new HikeDraft())->setTitle('Randonnée sans photo');
        $this->setId($hike, 102);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(InstagramPublication::class));
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $publication = $this->scheduler(
            $entityManager,
            $this->repositoryWithoutExistingPublication(InstagramPublicationSourceType::Hike, 102),
            $messageBus,
        )->prepareFirstPublication($hike);

        self::assertInstanceOf(InstagramPublication::class, $publication);
        self::assertSame(InstagramPublicationStatus::NoMedia, $publication->getStatus());
        self::assertTrue($publication->isTerminal());
        self::assertSame(0, $publication->getTotalMediaCount());
        self::assertSame(0, $publication->getTotalBatchCount());
        $this->setId($publication, 502);
        self::assertFalse($this->scheduler($entityManager, null, $messageBus)->dispatchAfterCommit($publication));
    }

    public function testAnExistingPersistentMarkerNeverCreatesANewAutomaticSnapshot(): void
    {
        $hike = (new HikeDraft())->setTitle('Ancienne randonnée');
        $this->setId($hike, 103);
        $existing = (new InstagramPublication(InstagramPublicationSourceType::Hike, 103))
            ->setStatus(InstagramPublicationStatus::LegacySkipped);
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findOneBySource')
            ->with(InstagramPublicationSourceType::Hike, 103)
            ->willReturn($existing);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        self::assertNull($this->scheduler($entityManager, $repository, $messageBus)->prepareFirstPublication($hike));
    }

    public function testFailedDispatchLeavesTheDurablePublicationPending(): void
    {
        $publication = $this->publicationWithTwoBatches(201);
        $publication->setStatus(InstagramPublicationStatus::Pending);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Transport detail that must not be persisted'));

        $dispatched = $this->scheduler($entityManager, null, $messageBus)->dispatchAfterCommit($publication);

        self::assertFalse($dispatched);
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertNull($publication->getLastDispatchedAt());
        self::assertSame('dispatch_failed', $publication->getLastErrorCode());
        self::assertStringNotContainsString('Transport detail', (string) $publication->getLastError());
    }

    public function testRetryKeepsPublishedBatchAndAllContainerIds(): void
    {
        $publication = $this->publicationWithTwoBatches(202);
        $batches = array_values($publication->getBatches()->toArray());
        $publishedBatch = $batches[0]
            ->setStatus(InstagramPublicationBatchStatus::Published)
            ->setContainerId('published-parent')
            ->setInstagramMediaId('published-media');
        $publishedMedia = $publishedBatch->getMedia()->first();
        self::assertInstanceOf(InstagramPublicationMedia::class, $publishedMedia);
        $publishedMedia->setContainerId('published-child');

        $failedBatch = $batches[1]
            ->setStatus(InstagramPublicationBatchStatus::Failed)
            ->setContainerId('failed-parent')
            ->setLastError('Temporary failure');
        $failedMedia = $failedBatch->getMedia()->first();
        self::assertInstanceOf(InstagramPublicationMedia::class, $failedMedia);
        $failedMedia
            ->setContainerId('created-child')
            ->setLastError('Temporary child failure');
        $publication
            ->setStatus(InstagramPublicationStatus::PartialFailure)
            ->synchronizeCounters();

        $repository = $this->repositoryReturningForUpdate($publication);
        $entityManager = $this->transactionalEntityManager(expectedFlushes: 2);
        $messageBus = $this->successfulMessageBus();

        self::assertTrue($this->scheduler($entityManager, $repository, $messageBus)->retry(202));
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertNotNull($publication->getLastDispatchedAt());
        self::assertSame(InstagramPublicationBatchStatus::Published, $publishedBatch->getStatus());
        self::assertSame('published-parent', $publishedBatch->getContainerId());
        self::assertSame('published-media', $publishedBatch->getInstagramMediaId());
        self::assertSame('published-child', $publishedMedia->getContainerId());
        self::assertSame(InstagramPublicationBatchStatus::Pending, $failedBatch->getStatus());
        self::assertSame('failed-parent', $failedBatch->getContainerId());
        self::assertSame('created-child', $failedMedia->getContainerId());
        self::assertNull($failedBatch->getLastError());
        self::assertNull($failedMedia->getLastError());
    }

    public function testRecoveryResetsAStaleProcessingLeaseAndItsProcessingBatch(): void
    {
        $publication = $this->publicationWithTwoBatches(203)
            ->setStatus(InstagramPublicationStatus::Processing)
            ->setProcessingToken('0123456789abcdef0123456789abcdef')
            ->setLastAttemptAt(new \DateTimeImmutable('-2 hours'))
            ->setLastDispatchedAt(new \DateTimeImmutable('-2 hours'));
        $processingBatch = $publication->getBatches()->first();
        self::assertInstanceOf(InstagramPublicationBatch::class, $processingBatch);
        $processingBatch
            ->setStatus(InstagramPublicationBatchStatus::Processing)
            ->setContainerId('container-kept');

        $recovered = $this->scheduler(
            $this->transactionalEntityManager(expectedFlushes: 2),
            $this->repositoryReturningForUpdate($publication),
            $this->successfulMessageBus(),
        )->recoverAndDispatch(
            203,
            new \DateTimeImmutable('-5 minutes'),
            new \DateTimeImmutable('-30 minutes'),
        );

        self::assertTrue($recovered);
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertNull($publication->getProcessingToken());
        self::assertSame(InstagramPublicationBatchStatus::Pending, $processingBatch->getStatus());
        self::assertSame('container-kept', $processingBatch->getContainerId());
        self::assertNotNull($publication->getLastDispatchedAt());
    }

    public function testRecoveryRechecksThePendingCutoffUnderLock(): void
    {
        $publication = $this->publicationWithTwoBatches(204)
            ->setStatus(InstagramPublicationStatus::Pending)
            ->setLastDispatchedAt(new \DateTimeImmutable());
        $entityManager = $this->transactionalEntityManager(expectedFlushes: 0);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        self::assertFalse($this->scheduler(
            $entityManager,
            $this->repositoryReturningForUpdate($publication),
            $messageBus,
        )->recoverAndDispatch(
            204,
            new \DateTimeImmutable('-5 minutes'),
            new \DateTimeImmutable('-30 minutes'),
        ));
    }

    /** @return array{HikeDraft, list<MediaAsset>} */
    private function hikeWithMedia(int $hikeId, int $mediaCount): array
    {
        $hike = (new HikeDraft())
            ->setTitle('Randonnée snapshot')
            ->setNotes('Résumé éditorial existant.');
        $this->setId($hike, $hikeId);
        $assets = [];

        for ($position = 1; $position <= $mediaCount; ++$position) {
            $asset = (new MediaAsset())
                ->setMediaType(MediaType::Image)
                ->setImageType(ImageType::Standard)
                ->setFilePath(sprintf('/uploads/media/photo-%d.webp', $position));
            $this->setId($asset, $position);
            $link = (new HikeDraftMedia())
                ->setMediaAsset($asset)
                ->setRole($position === 1 ? MediaRole::Cover : MediaRole::Gallery)
                ->setPosition($position);
            $this->setId($link, $position);
            $hike->addMediaLink($link);
            $assets[] = $asset;
        }

        return [$hike, $assets];
    }

    private function publicationWithTwoBatches(int $id): InstagramPublication
    {
        $publication = new InstagramPublication(InstagramPublicationSourceType::Hike, $id, 'Légende');
        $this->setId($publication, $id);

        for ($batchPosition = 1; $batchPosition <= 2; ++$batchPosition) {
            $batch = new InstagramPublicationBatch($publication, $batchPosition, sprintf('Lot %d/2', $batchPosition));
            $asset = (new MediaAsset())->setFilePath(sprintf('/uploads/media/%d.webp', $batchPosition));
            $this->setId($asset, $batchPosition);
            new InstagramPublicationMedia(
                $publication,
                $batch,
                $asset,
                $batchPosition,
                $batchPosition,
                1,
                sprintf('https://estela-exploration.fr/uploads/media/%d.webp', $batchPosition),
                $batch->getCaption(),
            );
        }

        return $publication->synchronizeCounters();
    }

    private function repositoryWithoutExistingPublication(
        InstagramPublicationSourceType $sourceType,
        int $sourceId,
    ): InstagramPublicationRepository&MockObject {
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findOneBySource')
            ->with($sourceType, $sourceId)
            ->willReturn(null);

        return $repository;
    }

    private function repositoryReturningForUpdate(
        InstagramPublication $publication,
    ): InstagramPublicationRepository&MockObject {
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findOneForUpdate')
            ->with($publication->getId())
            ->willReturn($publication);

        return $repository;
    }

    private function transactionalEntityManager(int $expectedFlushes): EntityManagerInterface&MockObject
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::exactly($expectedFlushes))->method('flush');

        return $entityManager;
    }

    private function successfulMessageBus(): MessageBusInterface&MockObject
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return $messageBus;
    }

    private function scheduler(
        ?EntityManagerInterface $entityManager = null,
        ?InstagramPublicationRepository $repository = null,
        ?MessageBusInterface $messageBus = null,
    ): InstagramPublicationScheduler {
        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $repository ??= $this->createStub(InstagramPublicationRepository::class);
        $messageBus ??= new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };
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

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
