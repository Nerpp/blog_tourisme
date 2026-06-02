<?php

namespace App\Service\Media;

use App\Entity\MediaAsset;
use App\Enum\MediaType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class MediaVariantService
{
    private const PUBLIC_POSTER_DIRECTORY = '/uploads/media/posters';

    public function __construct(
        private readonly ImageVariantGenerator $imageVariantGenerator,
        private readonly LoggerInterface $logger,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /** @return list<string> */
    public function supportedOutputFormats(): array
    {
        return $this->imageVariantGenerator->supportedOutputFormats();
    }

    public function supportsAvif(): bool
    {
        return $this->imageVariantGenerator->supportsAvif();
    }

    public function supportsWebp(): bool
    {
        return $this->imageVariantGenerator->supportsWebp();
    }

    public function supports(MediaAsset $media): bool
    {
        return match ($media->getMediaType()) {
            MediaType::Image => $media->getFilePath() !== null
                && $this->isLocalPublicPath($media->getFilePath())
                && (
                    $media->getMimeType() === null
                    || $this->imageVariantGenerator->supportsMimeType($media->getMimeType())
                ),
            MediaType::Video => $media->getThumbnailPath() !== null
                && $this->isLocalPublicPath($media->getThumbnailPath()),
        };
    }

    public function hasUsableVariants(MediaAsset $media): bool
    {
        $variants = $media->getVariants();
        if (!is_array($variants)) {
            return false;
        }

        if ($media->getMediaType() === MediaType::Video) {
            return isset($variants['poster']) && is_array($variants['poster']);
        }

        return isset($variants['thumb'], $variants['medium'], $variants['large']);
    }

    /**
     * @return array{status: string, generated: bool, message: string|null}
     */
    public function generateForMedia(MediaAsset $media, bool $force = false): array
    {
        if (!$this->supports($media)) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Média local image non supporté ou média externe.',
            ];
        }

        if (!$force && $this->hasUsableVariants($media)) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Variantes déjà présentes.',
            ];
        }

        try {
            if ($media->getMediaType() === MediaType::Video) {
                return $this->generatePosterVariants($media);
            }

            return $this->generateImageVariants($media);
        } catch (\Throwable $exception) {
            $this->logger->warning('La génération de variantes média a échoué.', [
                'mediaId' => $media->getId(),
                'filePath' => $media->getFilePath(),
                'thumbnailPath' => $media->getThumbnailPath(),
                'absolutePath' => $this->absolutePath($this->sourcePath($media)),
                'exception' => $exception,
            ]);

            return [
                'status' => 'error',
                'generated' => false,
                'message' => $this->diagnosticMessage($media, $exception->getMessage()),
            ];
        }
    }

    /**
     * @return array{status: string, generated: bool, message: string|null}
     */
    private function generateImageVariants(MediaAsset $media): array
    {
        $filePath = $media->getFilePath();
        if ($filePath === null) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Aucun fichier local image.',
            ];
        }

        if (!$this->localPublicFileExists($filePath)) {
            return [
                'status' => 'error',
                'generated' => false,
                'message' => $this->diagnosticMessage($media, 'Fichier source image introuvable.'),
            ];
        }

        $variants = $this->imageVariantGenerator->generate($filePath, $filePath);
        $media->setVariants($variants);

        if (isset($variants['source']) && is_array($variants['source'])) {
            $media
                ->setMimeType(is_string($variants['source']['mimeType'] ?? null) ? $variants['source']['mimeType'] : $media->getMimeType())
                ->setWidth(is_numeric($variants['source']['width'] ?? null) ? (int) $variants['source']['width'] : $media->getWidth())
                ->setHeight(is_numeric($variants['source']['height'] ?? null) ? (int) $variants['source']['height'] : $media->getHeight());
        }

        if ($media->getThumbnailPath() === null && isset($variants['thumb']['fallback'])) {
            $media->setThumbnailPath((string) $variants['thumb']['fallback']);
        }

        return [
            'status' => 'generated',
            'generated' => true,
            'message' => null,
        ];
    }

    /**
     * @return array{status: string, generated: bool, message: string|null}
     */
    private function generatePosterVariants(MediaAsset $media): array
    {
        $thumbnailPath = $media->getThumbnailPath();
        if ($thumbnailPath === null) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => 'Aucun poster manuel.',
            ];
        }

        if (!$this->localPublicFileExists($thumbnailPath)) {
            return [
                'status' => 'skipped',
                'generated' => false,
                'message' => $this->diagnosticMessage($media, 'Vidéo externe avec poster local manquant.'),
            ];
        }

        $existingVariants = $media->getVariants() ?? [];
        $existingVariants['poster'] = $this->imageVariantGenerator->generate(
            $thumbnailPath,
            $thumbnailPath.'_poster',
            self::PUBLIC_POSTER_DIRECTORY,
        );
        $media->setVariants($existingVariants);

        return [
            'status' => 'generated',
            'generated' => true,
            'message' => null,
        ];
    }

    private function isLocalPublicPath(string $path): bool
    {
        return !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://');
    }

    private function localPublicFileExists(string $path): bool
    {
        $absolutePath = $this->absolutePath($path);

        return $absolutePath !== null && is_file($absolutePath);
    }

    private function diagnosticMessage(MediaAsset $media, string $message): string
    {
        return sprintf(
            '%s mediaId=%s filePath=%s thumbnailPath=%s chemin absolu résolu=%s',
            $message,
            $media->getId() ?? 'non persisté',
            $media->getFilePath() ?? '-',
            $media->getThumbnailPath() ?? '-',
            $this->absolutePath($this->sourcePath($media)) ?? '-',
        );
    }

    private function sourcePath(MediaAsset $media): ?string
    {
        if ($media->getMediaType() === MediaType::Video) {
            return $media->getThumbnailPath();
        }

        return $media->getFilePath();
    }

    private function absolutePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (!$this->isLocalPublicPath($path)) {
            return 'URL externe';
        }

        return rtrim($this->parameterBag->get('kernel.project_dir'), '/').'/public/'.ltrim($path, '/');
    }
}
