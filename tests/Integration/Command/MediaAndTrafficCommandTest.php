<?php

namespace App\Tests\Integration\Command;

use App\Entity\MediaAsset;
use App\Entity\TrafficEvent;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\Media\MediaVariantService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MediaAndTrafficCommandTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testGenerateMediaVariantsDryRunReportsProcessableMediaWithoutChangingIt(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 80, 40);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setTitle('Commande variantes dry-run')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));
        $this->persist($media);

        $tester = $this->commandTester('app:media:generate-variants');
        $status = $tester->execute([
            '--id' => (string) $media->getId(),
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(sprintf('#%d serait traité', $media->getId()), $tester->getDisplay());
        self::assertNull($media->getVariants());
        self::assertNull($media->getThumbnailPath());
    }

    public function testGenerateMediaVariantsRejectsContradictoryOptions(): void
    {
        $tester = $this->commandTester('app:media:generate-variants');
        $status = $tester->execute([
            '--force' => true,
            '--missing-only' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('contradictoires', $tester->getDisplay());
    }

    public function testMediaCommandsWarnWhenRestrictedMediaDoesNotExist(): void
    {
        foreach (['app:media:generate-variants', 'app:media:cleanup-public-masters'] as $commandName) {
            $tester = $this->commandTester($commandName);
            $status = $tester->execute(['--id' => '999999999']);

            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('Aucun média trouvé.', $tester->getDisplay());
        }
    }

    public function testCleanupPublicMastersDryRunReportsDeletableMasterWithoutDeletingIt(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setTitle('Commande cleanup dry-run')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));
        $this->persist($media);

        $this->mediaVariantService()->generateForMedia($media, force: true);
        $this->trackVariantFiles($media->getVariants());
        $this->entityManager->flush();

        $tester = $this->commandTester('app:media:cleanup-public-masters');
        $status = $tester->execute([
            '--id' => (string) $media->getId(),
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(sprintf('#%d supprimable', $media->getId()), $tester->getDisplay());
        self::assertFileExists($source);
        self::assertSame(TestImageFactory::publicPathFor($source), $media->getFilePath());
    }

    public function testGenerateVideoThumbnailsDryRunReportsYoutubeVideoWithoutWritingThumbnail(): void
    {
        $media = (new MediaAsset())
            ->setTitle('Commande miniature vidéo dry-run')
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->persist($media);

        $tester = $this->commandTester('app:media:generate-video-thumbnails');
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(sprintf('#%d serait traitée', $media->getId()), $tester->getDisplay());
        self::assertNull($media->getThumbnailPath());
    }

    public function testPruneTrafficEventsSupportsDryRunAndNonInteractiveDeletion(): void
    {
        $oldEvent = (new TrafficEvent())
            ->setOccurredAt(new \DateTimeImmutable('-400 days'))
            ->setPath('/ancien-trafic-test');
        $recentEvent = (new TrafficEvent())
            ->setOccurredAt(new \DateTimeImmutable('-2 days'))
            ->setPath('/trafic-recent-test');
        $this->persist($oldEvent, $recentEvent);
        $oldId = $oldEvent->getId();
        $recentId = $recentEvent->getId();
        self::assertIsInt($oldId);
        self::assertIsInt($recentId);

        $dryRunTester = $this->commandTester('app:traffic:prune');
        $dryRunStatus = $dryRunTester->execute([
            '--days' => '180',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunStatus);
        self::assertStringContainsString('seraient supprimés', $dryRunTester->getDisplay());
        self::assertNotNull($this->entityManager->find(TrafficEvent::class, $oldId));

        $deleteTester = $this->commandTester('app:traffic:prune');
        $deleteStatus = $deleteTester->execute(['--days' => '180'], ['interactive' => false]);
        $this->entityManager->clear();

        self::assertSame(Command::SUCCESS, $deleteStatus);
        self::assertStringContainsString('supprimés', $deleteTester->getDisplay());
        self::assertNull($this->entityManager->find(TrafficEvent::class, $oldId));
        self::assertNotNull($this->entityManager->find(TrafficEvent::class, $recentId));
    }

    private function commandTester(string $name): CommandTester
    {
        $application = new Application(static::$kernel);
        $command = $application->find($name);

        return new CommandTester($command);
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    /** @param array<string, mixed>|null $variants */
    private function trackVariantFiles(?array $variants): void
    {
        if (!is_array($variants)) {
            return;
        }

        array_walk_recursive($variants, function (mixed $value): void {
            if (!is_string($value) || !str_starts_with($value, '/uploads/media/')) {
                return;
            }

            $file = TestImageFactory::projectDir().'/public/'.ltrim($value, '/');
            if (is_file($file)) {
                $this->files[] = $file;
            }
        });
    }

    private function mediaVariantService(): MediaVariantService
    {
        $service = $this->service(MediaVariantService::class);
        self::assertInstanceOf(MediaVariantService::class, $service);

        return $service;
    }
}
