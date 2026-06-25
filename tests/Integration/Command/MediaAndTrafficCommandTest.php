<?php

namespace App\Tests\Integration\Command;

use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\TrafficEvent;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
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

    public function testGenerateMediaVariantsHelpOnlyExposesActiveOptions(): void
    {
        $application = new Application(static::$kernel);
        $command = $application->find('app:media:generate-variants');
        $definition = $command->getDefinition();

        self::assertFalse($definition->hasOption('missing-only'));
        self::assertTrue($definition->hasOption('force'));
        self::assertTrue($definition->hasOption('id'));
        self::assertTrue($definition->hasOption('dry-run'));
    }

    public function testGenerateMediaVariantsGeneratesFilesAndReportsMissingSourceFailure(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $validMedia = (new MediaAsset())
            ->setTitle('Commande variantes succès')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));
        $missingMedia = (new MediaAsset())
            ->setTitle('Commande variantes source absente')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/missing-command-source.jpg');
        $this->persist($validMedia, $missingMedia);

        $successTester = $this->commandTester('app:media:generate-variants');
        $successStatus = $successTester->execute([
            '--id' => (string) $validMedia->getId(),
            '--force' => true,
        ]);
        $this->trackVariantFiles($validMedia->getVariants());

        self::assertSame(Command::SUCCESS, $successStatus);
        self::assertStringContainsString(sprintf('#%d variantes générées', $validMedia->getId()), $successTester->getDisplay());
        self::assertNotNull($validMedia->getVariants());
        self::assertNull($missingMedia->getVariants());

        $regenerationTester = $this->commandTester('app:media:generate-variants');
        $regenerationStatus = $regenerationTester->execute([
            '--id' => (string) $validMedia->getId(),
            '--force' => true,
        ]);
        $this->trackVariantFiles($validMedia->getVariants());

        self::assertSame(Command::SUCCESS, $regenerationStatus);
        self::assertStringContainsString(sprintf('#%d variantes générées', $validMedia->getId()), $regenerationTester->getDisplay());
        self::assertNotNull($validMedia->getVariants());
        self::assertNull($missingMedia->getVariants());

        $failureTester = $this->commandTester('app:media:generate-variants');
        $failureStatus = $failureTester->execute([
            '--id' => (string) $missingMedia->getId(),
            '--force' => true,
        ]);

        self::assertSame(Command::FAILURE, $failureStatus);
        self::assertStringContainsString(sprintf('#%d erreur', $missingMedia->getId()), $failureTester->getDisplay());
    }

    public function testGenerateMediaVariantsSkipsExternalImageAndVideoWithoutLocalPoster(): void
    {
        $externalImage = (new MediaAsset())
            ->setTitle('Image externe sans variantes')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('https://example.test/external-image.jpg');
        $videoWithoutPoster = (new MediaAsset())
            ->setTitle('Vidéo sans poster local')
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setExternalUrl('https://example.test/external-video.mp4');
        $this->persist($externalImage, $videoWithoutPoster);

        foreach ([$externalImage, $videoWithoutPoster] as $media) {
            $tester = $this->commandTester('app:media:generate-variants');
            $status = $tester->execute(['--id' => (string) $media->getId()]);

            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString(sprintf('#%d ignoré : média externe ou type non supporté', $media->getId()), $tester->getDisplay());
            self::assertNull($media->getVariants());
        }
    }

    public function testGenerateMediaVariantsNormalExecutionSkipsUsableVariants(): void
    {
        $variants = [
            'thumb' => ['webp' => '/uploads/media/variants/thumb.webp'],
            'mobile' => ['webp' => '/uploads/media/variants/mobile.webp'],
            'medium' => ['webp' => '/uploads/media/variants/medium.webp'],
            'large' => ['webp' => '/uploads/media/variants/large.webp'],
        ];
        $media = (new MediaAsset())
            ->setTitle('Image avec variantes existantes')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/existing-master.jpg')
            ->setVariants($variants);
        $this->persist($media);

        $tester = $this->commandTester('app:media:generate-variants');
        $status = $tester->execute(['--id' => (string) $media->getId()]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(sprintf('#%d ignoré : variantes déjà présentes', $media->getId()), $tester->getDisplay());
        self::assertSame($variants, $media->getVariants());
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

    public function testTargetedCommandsRejectInvalidNumericOptionsBeforeAnyMutation(): void
    {
        $media = (new MediaAsset())
            ->setTitle('Média conservé avec option invalide')
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/option-invalide.jpg');
        $trafficEvent = (new TrafficEvent())
            ->setOccurredAt(new \DateTimeImmutable('-400 days'))
            ->setPath('/trafic-option-invalide');
        $this->persist($media, $trafficEvent);
        $mediaId = $media->getId();
        $trafficEventId = $trafficEvent->getId();
        self::assertIsInt($mediaId);
        self::assertIsInt($trafficEventId);

        foreach ([
            ['app:media:cleanup-orphans', ['--id' => 'not-an-id', '--force' => true]],
            ['app:media:cleanup-public-masters', ['--id' => (string) PHP_INT_MAX.'0']],
            ['app:media:cleanup-standard-legacy-variants', ['--id' => 'not-an-id']],
            ['app:media:seo-fill', ['--hike-id' => '12.5', '--force' => true]],
            ['app:media:generate-variants', ['--id' => '-1', '--force' => true]],
            ['app:traffic:prune', ['--days' => 'not-a-number']],
        ] as [$commandName, $arguments]) {
            $tester = $this->commandTester($commandName);
            $status = $tester->execute($arguments, ['interactive' => false]);

            self::assertSame(Command::INVALID, $status);
            self::assertStringContainsString('doit être un entier strictement positif', $tester->getDisplay());
        }

        self::assertNotNull($this->entityManager->find(MediaAsset::class, $mediaId));
        self::assertNotNull($this->entityManager->find(TrafficEvent::class, $trafficEventId));
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

    public function testCleanupPublicMastersDeletesSafeMasterAndSkipsUnsupportedMedia(): void
    {
        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory(), 120, 60);
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setTitle('Commande cleanup suppression')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath(TestImageFactory::publicPathFor($source));
        $video = (new MediaAsset())
            ->setTitle('Commande cleanup vidéo ignorée')
            ->setMediaType(MediaType::Video)
            ->setExternalUrl('https://example.test/video');
        $this->persist($media, $video);

        $this->mediaVariantService()->generateForMedia($media, force: true);
        $this->trackVariantFiles($media->getVariants());
        $this->entityManager->flush();

        $deleteTester = $this->commandTester('app:media:cleanup-public-masters');
        $deleteStatus = $deleteTester->execute(['--id' => (string) $media->getId()]);

        self::assertSame(Command::SUCCESS, $deleteStatus);
        self::assertStringContainsString(sprintf('#%d supprimé', $media->getId()), $deleteTester->getDisplay());
        self::assertFileDoesNotExist($source);
        self::assertNull($media->getFilePath());

        $secondDeleteTester = $this->commandTester('app:media:cleanup-public-masters');
        $secondDeleteStatus = $secondDeleteTester->execute(['--id' => (string) $media->getId()]);

        self::assertSame(Command::SUCCESS, $secondDeleteStatus);
        self::assertStringContainsString(sprintf('#%d ignoré : chemin maître absent ou non supprimable', $media->getId()), $secondDeleteTester->getDisplay());
        self::assertFileDoesNotExist($source);
        self::assertNull($media->getFilePath());

        $skipTester = $this->commandTester('app:media:cleanup-public-masters');
        $skipStatus = $skipTester->execute(['--id' => (string) $video->getId()]);

        self::assertSame(Command::SUCCESS, $skipStatus);
        self::assertStringContainsString(sprintf('#%d ignoré', $video->getId()), $skipTester->getDisplay());
    }

    public function testCleanupStandardLegacyVariantsDryRunThenDeletesOnlyStandardLegacyFiles(): void
    {
        $activeThumb = TestImageFactory::createWebp($this->publicVariantDirectory(), 120, 60, 'command-active-thumb.webp');
        $activeMobile = TestImageFactory::createWebp($this->publicVariantDirectory(), 160, 80, 'command-active-mobile.webp');
        $activeMedium = TestImageFactory::createWebp($this->publicVariantDirectory(), 200, 100, 'command-active-medium.webp');
        $activeLarge = TestImageFactory::createWebp($this->publicVariantDirectory(), 240, 120, 'command-active-large.webp');
        $legacyJpeg = TestImageFactory::createJpeg($this->publicVariantDirectory(), 40, 20, 'command-legacy-thumb.jpg');
        $legacyPng = TestImageFactory::createPng($this->publicVariantDirectory(), 40, 20, 'command-legacy-medium.png');
        $legacyAvif = TestImageFactory::createTextFile($this->publicVariantDirectory(), 'avif', 'legacy avif');
        $specialLegacyJpeg = TestImageFactory::createJpeg($this->publicVariantDirectory(), 40, 20, 'command-special-legacy.jpg');
        array_push(
            $this->files,
            $activeThumb,
            $activeMobile,
            $activeMedium,
            $activeLarge,
            $legacyJpeg,
            $legacyPng,
            $legacyAvif,
            $specialLegacyJpeg,
        );
        $standard = (new MediaAsset())
            ->setTitle('Commande anciennes variantes standard')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setThumbnailPath($this->publicVariantPath($activeThumb))
            ->setVariants([
                'source' => [
                    'path' => '/uploads/media/source.jpg',
                    'formats' => ['fallback', 'webp', 'avif'],
                ],
                'thumb' => [
                    'fallback' => $this->publicVariantPath($legacyJpeg),
                    'fallbackFormat' => 'image/jpeg',
                    'webp' => $this->publicVariantPath($activeThumb),
                    'width' => 120,
                    'height' => 60,
                ],
                'mobile' => [
                    'webp' => $this->publicVariantPath($activeMobile),
                    'width' => 160,
                    'height' => 80,
                ],
                'medium' => [
                    'fallback' => $this->publicVariantPath($legacyPng),
                    'webp' => $this->publicVariantPath($activeMedium),
                    'width' => 200,
                    'height' => 100,
                ],
                'large' => [
                    'avif' => $this->publicVariantPath($legacyAvif),
                    'webp' => $this->publicVariantPath($activeLarge),
                    'width' => 240,
                    'height' => 120,
                ],
            ]);
        $special = (new MediaAsset())
            ->setTitle('Commande anciennes variantes panorama')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Panorama)
            ->setVariants([
                'thumb' => [
                    'fallback' => $this->publicVariantPath($specialLegacyJpeg),
                    'webp' => $this->publicVariantPath($activeThumb),
                    'width' => 120,
                    'height' => 60,
                ],
            ]);
        $this->persist($standard, $special);

        $dryRunTester = $this->commandTester('app:media:cleanup-standard-legacy-variants');
        $dryRunStatus = $dryRunTester->execute([
            '--id' => (string) $standard->getId(),
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunStatus);
        self::assertStringContainsString(sprintf('#%d supprimable', $standard->getId()), $dryRunTester->getDisplay());
        self::assertStringContainsString(sprintf('#%d à nettoyer : métadonnées standard', $standard->getId()), $dryRunTester->getDisplay());
        self::assertFileExists($legacyJpeg);
        self::assertFileExists($legacyPng);
        self::assertFileExists($legacyAvif);
        self::assertArrayHasKey('fallback', $standard->getVariants()['thumb']);

        $deleteTester = $this->commandTester('app:media:cleanup-standard-legacy-variants');
        $deleteStatus = $deleteTester->execute(['--id' => (string) $standard->getId()]);

        self::assertSame(Command::SUCCESS, $deleteStatus);
        self::assertStringContainsString(sprintf('#%d supprimé', $standard->getId()), $deleteTester->getDisplay());
        self::assertStringContainsString('3 fichier(s)', $deleteTester->getDisplay());
        self::assertStringContainsString('supprimé(s)', $deleteTester->getDisplay());
        self::assertFileDoesNotExist($legacyJpeg);
        self::assertFileDoesNotExist($legacyPng);
        self::assertFileDoesNotExist($legacyAvif);
        foreach ([$activeThumb, $activeMobile, $activeMedium, $activeLarge, $specialLegacyJpeg] as $keptFile) {
            self::assertFileExists($keptFile);
        }
        self::assertIsArray($standard->getVariants());
        self::assertSame(['webp'], $standard->getVariants()['source']['formats']);
        self::assertSame(['webp', 'width', 'height'], array_keys($standard->getVariants()['thumb']));
        self::assertArrayNotHasKey('fallback', $standard->getVariants()['medium']);
        self::assertArrayNotHasKey('avif', $standard->getVariants()['large']);

        $secondTester = $this->commandTester('app:media:cleanup-standard-legacy-variants');
        $secondStatus = $secondTester->execute(['--id' => (string) $standard->getId()]);

        self::assertSame(Command::SUCCESS, $secondStatus);
        self::assertStringContainsString(sprintf('#%d ignoré : aucune variante héritée non-WebP', $standard->getId()), $secondTester->getDisplay());

        $specialTester = $this->commandTester('app:media:cleanup-standard-legacy-variants');
        $specialStatus = $specialTester->execute(['--id' => (string) $special->getId()]);

        self::assertSame(Command::SUCCESS, $specialStatus);
        self::assertStringContainsString(sprintf('#%d ignoré : média non standard', $special->getId()), $specialTester->getDisplay());
        self::assertFileExists($specialLegacyJpeg);
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

    public function testGenerateVideoThumbnailsGeneratesYoutubeThumbnailAndSkipsUnsupportedVideo(): void
    {
        $youtube = (new MediaAsset())
            ->setTitle('Commande miniature YouTube')
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $unsupported = (new MediaAsset())
            ->setTitle('Commande miniature externe non supportée')
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setExternalUrl('https://example.test/video');
        $this->persist($youtube, $unsupported);

        $tester = $this->commandTester('app:media:generate-video-thumbnails');
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(sprintf('#%d miniature générée', $youtube->getId()), $tester->getDisplay());
        self::assertStringContainsString(sprintf('#%d ignorée', $unsupported->getId()), $tester->getDisplay());
        self::assertSame('https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $youtube->getThumbnailPath());
    }

    public function testSeoFillDryRunThenForceUpdatesTechnicalTextForScopedHikeMedia(): void
    {
        $user = $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
        $hike = (new HikeDraft())
            ->setTitle('Boucle du Canigou')
            ->setSlug($this->uniqueToken('seo-hike'))
            ->setStatus(HikeDraftStatus::Draft)
            ->setCreatedBy($user);
        $media = (new MediaAsset())
            ->setTitle('IMG_1234.JPG')
            ->setAltText(null)
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/IMG_1234.JPG');
        $link = (new HikeDraftMedia())
            ->setHikeDraft($hike)
            ->setMediaAsset($media);
        $hike->addMediaLink($link);
        $media->getHikeDraftLinks()->add($link);
        $this->persist($user, $hike, $media, $link);

        $dryRunTester = $this->commandTester('app:media:seo-fill');
        $dryRunStatus = $dryRunTester->execute([
            '--id' => (string) $media->getId(),
            '--hike-id' => (string) $hike->getId(),
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunStatus);
        self::assertStringContainsString(sprintf('MediaAsset #%d serait mis à jour', $media->getId()), $dryRunTester->getDisplay());
        self::assertSame('IMG_1234.JPG', $media->getTitle());
        self::assertNull($media->getAltText());

        $forceTester = $this->commandTester('app:media:seo-fill');
        $forceStatus = $forceTester->execute([
            '--id' => (string) $media->getId(),
            '--hike-id' => (string) $hike->getId(),
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $forceStatus);
        self::assertStringContainsString(sprintf('MediaAsset #%d mis à jour', $media->getId()), $forceTester->getDisplay());
        self::assertNotSame('IMG_1234.JPG', $media->getTitle());
        self::assertStringContainsString('Boucle du Canigou', (string) $media->getTitle());
        self::assertNotSame('', $media->getAltText());
    }

    public function testSeoFillIgnoresUnknownMediaAndExistingHumanText(): void
    {
        $orphan = (new MediaAsset())
            ->setTitle('DSC_9876.JPG')
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/DSC_9876.JPG');
        $this->persist($orphan);

        $tester = $this->commandTester('app:media:seo-fill');
        $status = $tester->execute([
            '--id' => (string) $orphan->getId(),
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Ignorés', $tester->getDisplay());
        self::assertSame('DSC_9876.JPG', $orphan->getTitle());
    }

    public function testCleanupOrphansRequiresModeAndDryRunKeepsOrphan(): void
    {
        $invalidTester = $this->commandTester('app:media:cleanup-orphans');
        $invalidStatus = $invalidTester->execute([]);

        self::assertSame(Command::INVALID, $invalidStatus);
        self::assertStringContainsString('Utilisez --dry-run', $invalidTester->getDisplay());

        $source = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory());
        $this->files[] = $source;
        $media = (new MediaAsset())
            ->setTitle('Commande orphelin dry-run')
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($source));
        $this->persist($media);
        $mediaId = $media->getId();

        $dryRunTester = $this->commandTester('app:media:cleanup-orphans');
        $dryRunStatus = $dryRunTester->execute([
            '--id' => (string) $mediaId,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $dryRunStatus);
        self::assertStringContainsString(sprintf('#%d orphelin (dry-run)', $mediaId), $dryRunTester->getDisplay());
        self::assertFileExists($source);
        self::assertNotNull($this->entityManager->find(MediaAsset::class, $mediaId));
    }

    public function testCleanupOrphansForceDeletesOrphanAndKeepsUsedMedia(): void
    {
        $orphanFile = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory());
        $usedFile = TestImageFactory::createJpeg(TestImageFactory::publicMediaDirectory());
        $this->files[] = $orphanFile;
        $this->files[] = $usedFile;
        $orphan = (new MediaAsset())
            ->setTitle('Commande orphelin suppression')
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($orphanFile));
        $used = (new MediaAsset())
            ->setTitle('Commande média utilisé')
            ->setMediaType(MediaType::Image)
            ->setFilePath(TestImageFactory::publicPathFor($usedFile));
        $place = (new Place())
            ->setName('Lieu commande cleanup '.$this->uniqueToken('place'))
            ->setSlug($this->uniqueToken('place-slug'))
            ->setStatus(ContentStatus::Draft)
            ->setDifficulty(PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::Unknown)
            ->setFeaturedImage($used);
        $this->persist($orphan, $used, $place);
        $orphanId = $orphan->getId();

        $deleteTester = $this->commandTester('app:media:cleanup-orphans');
        $deleteStatus = $deleteTester->execute([
            '--id' => (string) $orphanId,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $deleteStatus);
        self::assertStringContainsString('1 supprimé(s)', $deleteTester->getDisplay());
        self::assertFileDoesNotExist($orphanFile);
        self::assertNull($this->entityManager->find(MediaAsset::class, $orphanId));

        $keepTester = $this->commandTester('app:media:cleanup-orphans');
        $keepStatus = $keepTester->execute([
            '--id' => (string) $used->getId(),
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $keepStatus);
        self::assertStringContainsString(sprintf('#%d conservé', $used->getId()), $keepTester->getDisplay());
        self::assertFileExists($usedFile);
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

    private function publicVariantDirectory(): string
    {
        $directory = TestImageFactory::projectDir().'/public/uploads/media/variants';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    private function publicVariantPath(string $absolutePath): string
    {
        return '/uploads/media/variants/'.basename($absolutePath);
    }
}
