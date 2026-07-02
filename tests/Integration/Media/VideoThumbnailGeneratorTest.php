<?php

namespace App\Tests\Integration\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\Media\PublicMediaPathValidator;
use App\Service\Media\VideoThumbnailGenerator;
use App\Service\VideoThumbnailResolver;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\TestImageFactory;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class VideoThumbnailGeneratorTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }

        (new Filesystem())->remove(array_reverse($this->directories));

        parent::tearDown();
    }

    public function testYoutubeVideoUsesExternalThumbnailWithoutFfmpeg(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $thumbnail = $this->generator()->generateForMedia($media);

        self::assertSame('https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $thumbnail);
        self::assertSame($thumbnail, $media->getThumbnailPath());
    }

    public function testInvalidYoutubeUrlDoesNotFallBackToLocalGeneration(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Youtube)
            ->setExternalUrl('https://www.youtube.com/watch?v=bad')
            ->setFilePath('https://example.test/video.mp4');

        self::assertNull($this->generator()->generateForMedia($media));
        self::assertNull($media->getThumbnailPath());
    }

    public function testExistingThumbnailIsKeptUnlessOverwriteIsRequested(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Local)
            ->setFilePath('/uploads/media/missing-video.mp4')
            ->setThumbnailPath('/uploads/media/existing-thumb.jpg');

        self::assertSame('/uploads/media/existing-thumb.jpg', $this->generator()->generateForMedia($media));
        self::assertNull($this->generator()->generateForMedia($media, overwrite: true));
        self::assertSame('/uploads/media/existing-thumb.jpg', $media->getThumbnailPath());
    }

    public function testSuccessfulLocalGenerationPromotesTemporaryThumbnailAtomically(): void
    {
        $publicPath = $this->createLocalVideo();
        $outputPath = $this->expectedOutputPath($publicPath, 'Vidéo atomique');
        $this->files[] = $outputPath;
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::SUCCESS);

        $thumbnail = $generator->generateFromPublicPath($publicPath, 'Vidéo atomique');

        self::assertSame('/uploads/media/video-thumbnails/'.basename($outputPath), $thumbnail);
        self::assertFileExists($outputPath);
        self::assertSame('simulated thumbnail', file_get_contents($outputPath));
        self::assertSame(1, $generator->extractionAttempts);
        self::assertCount(1, $generator->extractionOutputPaths);
        self::assertSame(dirname($outputPath), dirname($generator->extractionOutputPaths[0]));
        self::assertMatchesRegularExpression(
            '/^\.video-thumbnail-[a-f0-9]{32}\.staging\.jpg$/',
            basename($generator->extractionOutputPaths[0]),
        );
        self::assertSame([], $this->temporaryThumbnails());
    }

    public function testGenerationCreatesMissingThumbnailDirectory(): void
    {
        $projectDirectory = TestImageFactory::testMediaDirectory().'/video-thumbnail-project-'.bin2hex(random_bytes(6));
        $mediaDirectory = $projectDirectory.'/public/uploads/media';
        self::assertTrue(mkdir($mediaDirectory, 0775, true));
        $this->directories[] = $projectDirectory;
        $video = $mediaDirectory.'/local.mp4';
        file_put_contents($video, 'simulated local video');
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::SUCCESS, projectDirectory: $projectDirectory);

        $thumbnail = $generator->generateFromPublicPath('/uploads/media/local.mp4', 'Nouvelle miniature');

        self::assertNotNull($thumbnail);
        self::assertDirectoryExists($projectDirectory.'/public/uploads/media/video-thumbnails');
        self::assertFileExists($projectDirectory.'/public'.$thumbnail);
        self::assertSame(1, $generator->extractionAttempts);
    }

    public function testMissingBasenameSeedFallsBackToVideoFilename(): void
    {
        $publicPath = $this->createLocalVideo();
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::SUCCESS);

        $thumbnail = $generator->generateFromPublicPath($publicPath);

        self::assertNotNull($thumbnail);
        self::assertStringStartsWith('/uploads/media/video-thumbnails/atomic-video-', $thumbnail);
        $this->files[] = TestImageFactory::projectDir().'/public'.$thumbnail;
    }

    public function testMissingAllowedMediaRootReturnsNullWithoutExtraction(): void
    {
        $projectDirectory = TestImageFactory::testMediaDirectory().'/video-thumbnail-missing-root-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($projectDirectory.'/public', 0775, true));
        $this->directories[] = $projectDirectory;
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::SUCCESS, projectDirectory: $projectDirectory);

        self::assertNull($generator->generateFromPublicPath('/uploads/media/video.mp4'));
        self::assertSame(0, $generator->extractionAttempts);
    }

    public function testFailedLocalGenerationRemovesPartialTemporaryFiles(): void
    {
        $publicPath = $this->createLocalVideo();
        $outputPath = $this->expectedOutputPath($publicPath, 'Échec atomique');
        $this->files[] = $outputPath;
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::FAIL_WITH_PARTIAL_FILE);

        self::assertNull($generator->generateFromPublicPath($publicPath, 'Échec atomique'));
        self::assertFileDoesNotExist($outputPath);
        self::assertSame(3, $generator->extractionAttempts);
        self::assertCount(3, array_unique($generator->extractionOutputPaths));
        self::assertSame([], $this->temporaryThumbnails());
    }

    public function testPromotionFailurePreservesExistingFinalThumbnail(): void
    {
        $publicPath = $this->createLocalVideo();
        $outputPath = $this->expectedOutputPath($publicPath, 'Miniature préservée');
        $this->ensureThumbnailDirectory();
        file_put_contents($outputPath, 'existing final thumbnail');
        $this->files[] = $outputPath;
        $generator = $this->simulatedGenerator(
            SimulatedVideoThumbnailGenerator::SUCCESS,
            promotionSucceeds: false,
        );

        self::assertNull($generator->generateFromPublicPath($publicPath, 'Miniature préservée'));
        self::assertFileExists($outputPath);
        self::assertSame('existing final thumbnail', file_get_contents($outputPath));
        self::assertSame(1, $generator->extractionAttempts);
        self::assertSame([], $this->temporaryThumbnails());
    }

    public function testSuccessfulProcessWithoutTemporaryFileDoesNotCreateFinalThumbnail(): void
    {
        $publicPath = $this->createLocalVideo();
        $outputPath = $this->expectedOutputPath($publicPath, 'Sortie absente');
        $this->files[] = $outputPath;
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::SUCCESS_WITHOUT_FILE);

        self::assertNull($generator->generateFromPublicPath($publicPath, 'Sortie absente'));
        self::assertFileDoesNotExist($outputPath);
        self::assertSame(3, $generator->extractionAttempts);
        self::assertSame([], $this->temporaryThumbnails());
    }

    public function testUnsafeOrMissingPublicPathReturnsNull(): void
    {
        $generator = $this->generator();

        self::assertNull($generator->generateFromPublicPath('/uploads/media/../secret.mp4'));
        self::assertNull($generator->generateFromPublicPath('https://example.test/video.mp4'));
        self::assertNull($generator->generateFromPublicPath('/uploads/media/missing-video.mp4'));
    }

    public function testNonVideoMediaIsIgnored(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setFilePath('/uploads/media/photo.jpg');

        self::assertNull($this->generator()->generateForMedia($media));
    }

    public function testUnsupportedExternalVideoDoesNotAttemptLocalGeneration(): void
    {
        $generator = $this->simulatedGenerator(SimulatedVideoThumbnailGenerator::SUCCESS);
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::External)
            ->setExternalUrl('https://example.test/video')
            ->setFilePath('https://example.test/video.mp4');

        $thumbnail = $generator->generateForMedia($media);

        self::assertNull($thumbnail);
        self::assertNull($media->getThumbnailPath());
        self::assertSame(0, $generator->extractionAttempts);
    }

    public function testMissingLocalVideoReturnsNullWithoutChangingMedia(): void
    {
        $media = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setVideoType(VideoType::Local)
            ->setFilePath('/uploads/media/missing-video.mp4')
            ->setThumbnailPath('');

        $thumbnail = $this->generator()->generateForMedia($media);

        self::assertNull($thumbnail);
        self::assertSame('', $media->getThumbnailPath());
    }

    public function testSymlinkResolvingOutsideMediaDirectoryIsRejected(): void
    {
        $outsideFile = sys_get_temp_dir().'/video-thumbnail-outside-'.bin2hex(random_bytes(4)).'.mp4';
        $symlink = TestImageFactory::publicMediaDirectory().'/video-thumbnail-link-'.bin2hex(random_bytes(4)).'.mp4';
        file_put_contents($outsideFile, 'outside media root');
        symlink($outsideFile, $symlink);
        $this->files[] = $symlink;
        $this->files[] = $outsideFile;

        self::assertNull($this->generator()->generateFromPublicPath(TestImageFactory::publicPathFor($symlink)));
    }

    private function generator(): VideoThumbnailGenerator
    {
        $generator = $this->service(VideoThumbnailGenerator::class);
        self::assertInstanceOf(VideoThumbnailGenerator::class, $generator);

        return $generator;
    }

    private function simulatedGenerator(
        string $behavior,
        bool $promotionSucceeds = true,
        ?string $projectDirectory = null,
    ): SimulatedVideoThumbnailGenerator
    {
        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturn($projectDirectory ?? TestImageFactory::projectDir());

        $generator = new SimulatedVideoThumbnailGenerator(
            $parameterBag,
            new AsciiSlugger(),
            new PublicMediaPathValidator(),
            new VideoThumbnailResolver(),
            new NullLogger(),
        );
        $generator->behavior = $behavior;
        $generator->promotionSucceeds = $promotionSucceeds;

        return $generator;
    }

    private function createLocalVideo(): string
    {
        $file = TestImageFactory::publicMediaDirectory().'/atomic-video-'.bin2hex(random_bytes(6)).'.mp4';
        file_put_contents($file, 'simulated local video');
        $this->files[] = $file;

        return TestImageFactory::publicPathFor($file);
    }

    private function expectedOutputPath(string $publicPath, string $basenameSeed): string
    {
        $safeName = strtolower((string) (new AsciiSlugger())->slug(pathinfo($basenameSeed, PATHINFO_FILENAME)));
        $filename = sprintf('%s-%s-thumb.jpg', $safeName, substr(sha1($publicPath), 0, 10));

        return $this->thumbnailDirectory().'/'.$filename;
    }

    /** @return list<string> */
    private function temporaryThumbnails(): array
    {
        return glob($this->thumbnailDirectory().'/.video-thumbnail-*.staging.jpg') ?: [];
    }

    private function ensureThumbnailDirectory(): void
    {
        $directory = $this->thumbnailDirectory();
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    private function thumbnailDirectory(): string
    {
        return TestImageFactory::projectDir().'/public/uploads/media/video-thumbnails';
    }
}

final class SimulatedVideoThumbnailGenerator extends VideoThumbnailGenerator
{
    public const SUCCESS = 'success';
    public const FAIL_WITH_PARTIAL_FILE = 'fail_with_partial_file';
    public const SUCCESS_WITHOUT_FILE = 'success_without_file';

    public string $behavior = self::SUCCESS;
    public bool $promotionSucceeds = true;
    public int $extractionAttempts = 0;
    /** @var list<string> */
    public array $extractionOutputPaths = [];

    protected function extractFrame(string $inputPath, string $outputPath, string $timeOffset): bool
    {
        ++$this->extractionAttempts;
        $this->extractionOutputPaths[] = $outputPath;

        return match ($this->behavior) {
            self::SUCCESS => file_put_contents($outputPath, 'simulated thumbnail') !== false,
            self::FAIL_WITH_PARTIAL_FILE => $this->writePartialFileAndFail($outputPath),
            self::SUCCESS_WITHOUT_FILE => true,
            default => false,
        };
    }

    protected function promoteTemporaryThumbnail(string $temporaryPath, string $outputPath): bool
    {
        return $this->promotionSucceeds
            ? parent::promoteTemporaryThumbnail($temporaryPath, $outputPath)
            : false;
    }

    private function writePartialFileAndFail(string $outputPath): bool
    {
        file_put_contents($outputPath, 'partial thumbnail');

        return false;
    }
}
