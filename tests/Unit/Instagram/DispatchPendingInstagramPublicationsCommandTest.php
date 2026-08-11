<?php

namespace App\Tests\Unit\Instagram;

use App\Command\DispatchPendingInstagramPublicationsCommand;
use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Entity\MediaAsset;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;

final class DispatchPendingInstagramPublicationsCommandTest extends TestCase
{
    public function testItRejectsInvalidOptionsBeforeQueryingTheRepository(): void
    {
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::never())->method('findRecoverableForDispatch');
        $tester = new CommandTester(new DispatchPendingInstagramPublicationsCommand(
            $repository,
            $this->scheduler(
                $this->createStub(EntityManagerInterface::class),
                $repository,
                $this->successfulMessageBus(expectDispatch: false),
            ),
        ));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('strictement positifs', $tester->getDisplay());
    }

    public function testItDispatchesARecoverablePendingPublicationThroughTheScheduler(): void
    {
        $publication = $this->pendingPublication(301)
            ->setLastDispatchedAt(new \DateTimeImmutable('-10 minutes'));
        $repository = $this->createMock(InstagramPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findRecoverableForDispatch')
            ->with(
                self::callback(static fn (\DateTimeImmutable $cutoff): bool => abs($cutoff->getTimestamp() - (time() - 60)) <= 3),
                self::callback(static fn (\DateTimeImmutable $cutoff): bool => abs($cutoff->getTimestamp() - (time() - 120)) <= 3),
                10,
            )
            ->willReturn([$publication]);
        $repository->expects(self::once())
            ->method('findOneForUpdate')
            ->with(301)
            ->willReturn($publication);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->expects(self::exactly(2))->method('flush');
        $command = new DispatchPendingInstagramPublicationsCommand(
            $repository,
            $this->scheduler($entityManager, $repository, $this->successfulMessageBus()),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--limit' => '10',
            '--pending-age' => '60',
            '--processing-age' => '120',
        ]));
        self::assertStringContainsString('1 publication(s) Instagram reprogrammée(s) sur 1 candidate(s)', $tester->getDisplay());
        self::assertNotNull($publication->getLastDispatchedAt());
    }

    private function pendingPublication(int $id): InstagramPublication
    {
        $publication = (new InstagramPublication(InstagramPublicationSourceType::Hike, $id, 'Légende'))
            ->setStatus(InstagramPublicationStatus::Pending);
        $this->setId($publication, $id);
        $batch = new InstagramPublicationBatch($publication, 1, 'Légende');
        $asset = (new MediaAsset())->setFilePath('/uploads/media/photo.webp');
        $this->setId($asset, 1);
        new InstagramPublicationMedia(
            $publication,
            $batch,
            $asset,
            1,
            1,
            1,
            'https://estela-exploration.fr/uploads/media/photo.webp',
            'Légende',
        );

        return $publication->synchronizeCounters();
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

    private function successfulMessageBus(bool $expectDispatch = true): MessageBusInterface&MockObject
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $expectation = $expectDispatch ? $messageBus->expects(self::once()) : $messageBus->expects(self::never());
        $expectation
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return $messageBus;
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
