<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use App\Enum\VideoType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\SluggerInterface;

final class VideoThumbnailGenerator
{
    private const PUBLIC_DIRECTORY = '/uploads/media/video-thumbnails';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly SluggerInterface $slugger,
        private readonly PublicMediaPathValidator $publicMediaPathValidator,
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
            if ($this->extractFrame($inputPath, $outputPath, $timeOffset)) {
                return self::PUBLIC_DIRECTORY.'/'.$outputFilename;
            }
        }

        if (is_file($outputPath)) {
            @unlink($outputPath);
        }

        return null;
    }

    private function extractFrame(string $inputPath, string $outputPath, string $timeOffset): bool
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

        if ($process->isSuccessful() && is_file($outputPath) && filesize($outputPath) !== false && filesize($outputPath) > 0) {
            return true;
        }

        $this->logger->warning('ffmpeg n’a pas pu extraire une miniature vidéo.', [
            'inputPath' => $inputPath,
            'timeOffset' => $timeOffset,
            'error' => trim($process->getErrorOutput()),
        ]);

        return false;
    }

    private function thumbnailForExternalVideo(MediaAsset $media): ?string
    {
        if ($media->getVideoType() !== VideoType::Youtube || !$media->getExternalUrl()) {
            return null;
        }

        $youtubeId = $this->extractYoutubeId($media->getExternalUrl());

        return $youtubeId !== null ? sprintf('https://img.youtube.com/vi/%s/hqdefault.jpg', $youtubeId) : null;
    }

    private function extractYoutubeId(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $parts['host']));
        $path = trim($parts['path'] ?? '', '/');
        $id = null;

        if ($host === 'youtu.be') {
            $id = explode('/', $path)[0] ?? null;
        }

        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtube-nocookie.com')) {
            if ($path === 'watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^(embed|shorts|live)/([^/?]+)#', $path, $matches)) {
                $id = $matches[2];
            }
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) ? $id : null;
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
