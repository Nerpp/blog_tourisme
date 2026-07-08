<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Service\VideoThumbnailResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\SluggerInterface;

class VideoThumbnailGenerator
{
    private const PUBLIC_DIRECTORY = '/uploads/media/video-thumbnails';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly SluggerInterface $slugger,
        private readonly PublicMediaPathValidator $publicMediaPathValidator,
        private readonly VideoThumbnailResolver $videoThumbnailResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function generateForMedia(MediaAsset $media, bool $overwrite = false): ?string
    {
        if ($media->getMediaType() !== MediaType::Video) {
            return null;
        }

        if (!$overwrite && $media->getThumbnailPath() !== null && $media->getThumbnailPath() !== '') {
            return $media->getThumbnailPath();
        }

        $thumbnailPath = $this->thumbnailForExternalVideo($media)
            ?? $this->generateFromPublicPath($media->getFilePath(), $media->getTitle() ?? $media->getFilePath());

        if ($thumbnailPath !== null) {
            $media->setThumbnailPath($thumbnailPath);
        }

        return $thumbnailPath;
    }

    public function generateFromPublicPath(?string $publicPath, ?string $basenameSeed = null): ?string
    {
        $inputPath = $this->resolveLocalPublicPath($publicPath);
        if ($inputPath === null) {
            return null;
        }

        $targetDirectory = $this->parameterBag->get('kernel.project_dir').'/public'.self::PUBLIC_DIRECTORY;
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $baseName = $basenameSeed !== null && trim($basenameSeed) !== ''
            ? pathinfo($basenameSeed, PATHINFO_FILENAME)
            : pathinfo($inputPath, PATHINFO_FILENAME);
        $safeName = strtolower((string) $this->slugger->slug($baseName ?: 'video'));
        $outputFilename = sprintf('%s-%s-thumb.jpg', $safeName, substr(sha1($publicPath ?? $inputPath), 0, 10));
        $outputPath = $targetDirectory.'/'.$outputFilename;

        foreach (['00:00:02', '00:00:01', '00:00:00.500'] as $timeOffset) {
            $temporaryPath = $targetDirectory.'/.video-thumbnail-'.bin2hex(random_bytes(16)).'.staging.jpg';

            try {
                if (!$this->extractFrame($inputPath, $temporaryPath, $timeOffset)) {
                    continue;
                }

                if (!$this->isUsableThumbnail($temporaryPath)) {
                    $this->logger->warning('FFmpeg n’a pas produit de miniature vidéo temporaire exploitable.', [
                        'inputPath' => $inputPath,
                        'timeOffset' => $timeOffset,
                    ]);

                    continue;
                }

                if (!$this->promoteTemporaryThumbnail($temporaryPath, $outputPath)) {
                    $this->logger->warning('La miniature vidéo temporaire n’a pas pu être promue.', [
                        'inputPath' => $inputPath,
                        'outputPath' => $outputPath,
                    ]);

                    return null;
                }

                return self::PUBLIC_DIRECTORY.'/'.$outputFilename;
            } finally {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
        }

        return null;
    }

    protected function extractFrame(string $inputPath, string $outputPath, string $timeOffset): bool
    {
        $process = new Process([
            'ffmpeg',
            '-y',
            '-ss',
            $timeOffset,
            '-i',
            $inputPath,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $outputPath,
        ]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            $this->logger->warning('Impossible de générer la miniature vidéo : ffmpeg est indisponible.', [
                'exception' => $exception,
                'inputPath' => $inputPath,
            ]);

            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $this->logger->warning('ffmpeg n’a pas pu extraire une miniature vidéo.', [
            'inputPath' => $inputPath,
            'timeOffset' => $timeOffset,
            'error' => trim($process->getErrorOutput()),
        ]);

        return false;
    }

    protected function promoteTemporaryThumbnail(string $temporaryPath, string $outputPath): bool
    {
        return @rename($temporaryPath, $outputPath);
    }

    private function thumbnailForExternalVideo(MediaAsset $media): ?string
    {
        if ($media->getVideoType() !== VideoType::Youtube || !$media->getExternalUrl()) {
            return null;
        }

        return $this->videoThumbnailResolver->getThumbnailUrl($media->getExternalUrl());
    }

    private function isUsableThumbnail(string $path): bool
    {
        $size = is_file($path) ? filesize($path) : false;

        return $size !== false && $size > 0;
    }

    private function resolveLocalPublicPath(?string $publicPath): ?string
    {
        if (!$this->publicMediaPathValidator->isSafeMediaUploadPath($publicPath)) {
            $this->logger->warning('Chemin vidéo refusé pour la génération de miniature.', [
                'publicPath' => $publicPath,
            ]);

            return null;
        }

        $allowedRoot = realpath($this->parameterBag->get('kernel.project_dir').'/public/uploads/media');
        if ($allowedRoot === false) {
            $this->logger->warning('Dossier public/uploads/media introuvable pour la génération de miniature vidéo.');

            return null;
        }

        $candidatePath = $this->parameterBag->get('kernel.project_dir').'/public/'.ltrim((string) $publicPath, '/');
        $realPath = realpath($candidatePath);
        if ($realPath === false || !is_file($realPath)) {
            return null;
        }

        $allowedRoot = rtrim($allowedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $allowedRoot)) {
            $this->logger->warning('Chemin vidéo hors du dossier autorisé refusé pour ffmpeg.', [
                'publicPath' => $publicPath,
                'realPath' => $realPath,
            ]);

            return null;
        }

        return $realPath;
    }
}
