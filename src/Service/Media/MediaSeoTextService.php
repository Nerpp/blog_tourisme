<?php

namespace App\Service\Media;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaType;
use Symfony\Component\String\Slugger\SluggerInterface;

final class MediaSeoTextService
{
    public function __construct(
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function publicTitle(MediaAsset $media, object|string|null $context = null, ?string $fallbackTitle = null): ?string
    {
        if (!$this->isTechnicalText($media->getTitle(), $media)) {
            return $this->cleanText($media->getTitle());
        }

        if (!$this->isTechnicalText($media->getCaption(), $media)) {
            return $this->cleanText($media->getCaption());
        }

        return $this->titleForContext($context, $media->getMediaType(), $media->getImageType(), $fallbackTitle);
    }

    public function publicAlt(MediaAsset $media, object|string|null $context = null, ?string $fallbackTitle = null): string
    {
        if (!$this->isTechnicalText($media->getAltText(), $media)) {
            return (string) $this->cleanText($media->getAltText());
        }

        return $this->altTextForContext($context, $media->getMediaType(), $media->getImageType(), $fallbackTitle);
    }

    public function titleForContext(
        object|string|null $context,
        MediaType $mediaType = MediaType::Image,
        ?ImageType $imageType = null,
        ?string $fallbackTitle = null,
    ): string {
        $metadata = $this->contextMetadata($context, $fallbackTitle);
        $subject = $metadata['title'];
        $destination = $metadata['destination'];

        return sprintf('%s de %s%s', $this->mediaTitlePrefix($mediaType, $imageType), $subject, $this->placeSuffix($destination));
    }

    public function altTextForContext(
        object|string|null $context,
        MediaType $mediaType = MediaType::Image,
        ?ImageType $imageType = null,
        ?string $fallbackTitle = null,
    ): string {
        $metadata = $this->contextMetadata($context, $fallbackTitle);
        $subject = $metadata['title'];
        $destination = $metadata['destination'];
        $department = $metadata['department'];

        return sprintf(
            '%s de %s%s%s',
            $this->mediaAltPrefix($mediaType, $imageType),
            $subject,
            $this->placeSuffix($destination),
            $this->departmentSuffix($department, $destination),
        );
    }

    public function filenameBaseForContext(
        object|string|null $context,
        MediaType $mediaType = MediaType::Image,
        ?ImageType $imageType = null,
        ?string $fallbackTitle = null,
    ): string {
        $metadata = $this->contextMetadata($context, $fallbackTitle);
        $parts = array_filter([
            $metadata['title'],
            $metadata['destination'],
            $this->filenameTypeToken($mediaType, $imageType),
        ], static fn (?string $part): bool => $part !== null && $part !== '');

        $slug = strtolower((string) $this->slugger->slug(implode(' ', $parts)));

        return $slug !== '' ? $slug : $this->filenameTypeToken($mediaType, $imageType);
    }

    public function isTechnicalText(?string $text, ?MediaAsset $media = null): bool
    {
        $text = $this->cleanText($text);
        if ($text === null) {
            return true;
        }

        $normalized = strtolower($text);
        $normalized = preg_replace('/\.(jpe?g|png|webp|avif|gif|mp4|mov|m4v)$/i', '', $normalized) ?? $normalized;

        foreach ([$media?->getFilePath(), $media?->getThumbnailPath()] as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $stem = strtolower(pathinfo($path, PATHINFO_FILENAME));
            if ($stem !== '' && $normalized === $stem) {
                return true;
            }
        }

        if (preg_match('/^(dji|dji-fly|dji_fly|pxl|img|image|photo|vid|video|screenshot|capture|whatsapp|signal)[-_ ]?\d/i', $normalized)) {
            return true;
        }

        if (str_contains($normalized, 'optimized') || str_contains($normalized, 'original')) {
            return true;
        }

        if (str_contains($normalized, '_') && preg_match('/\d{6,}/', $normalized)) {
            return true;
        }

        return strlen($normalized) > 32 && preg_match('/[a-f0-9]{10,}/', $normalized) === 1;
    }

    private function cleanText(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{title: string, destination: ?string, department: ?string}
     */
    private function contextMetadata(object|string|null $context, ?string $fallbackTitle): array
    {
        if (is_string($context)) {
            return [
                'title' => $this->cleanText($context) ?? $this->cleanText($fallbackTitle) ?? 'ce lieu',
                'destination' => null,
                'department' => null,
            ];
        }

        $title = $this->contentTitle($context) ?? $this->cleanText($fallbackTitle) ?? 'ce lieu';
        $destination = $this->contentDestination($context);

        return [
            'title' => $title,
            'destination' => $destination?->getName() ?? $this->detectedValue($context, 'getDetectedCommuneName'),
            'department' => $this->destinationAncestorName($destination, DestinationType::Department)
                ?? $this->detectedValue($context, 'getDetectedDepartmentName'),
        ];
    }

    private function contentTitle(object|null $context): ?string
    {
        if ($context instanceof HikeDraft || $context instanceof CityVisitDraft) {
            return $this->cleanText($context->getTitle());
        }

        if ($context instanceof Place) {
            return $this->cleanText($context->getName());
        }

        if ($context !== null && method_exists($context, 'getTitle')) {
            return $this->cleanText($context->getTitle());
        }

        if ($context !== null && method_exists($context, 'getName')) {
            return $this->cleanText($context->getName());
        }

        return null;
    }

    private function contentDestination(object|null $context): ?Destination
    {
        if ($context !== null && method_exists($context, 'getDestination')) {
            $destination = $context->getDestination();

            return $destination instanceof Destination ? $destination : null;
        }

        return null;
    }

    private function detectedValue(object|null $context, string $method): ?string
    {
        if ($context !== null && method_exists($context, $method)) {
            return $this->cleanText($context->{$method}());
        }

        return null;
    }

    private function destinationAncestorName(?Destination $destination, DestinationType $type): ?string
    {
        while ($destination instanceof Destination) {
            if ($destination->getType() === $type) {
                return $destination->getName();
            }

            $destination = $destination->getParent();
        }

        return null;
    }

    private function placeSuffix(?string $destination): string
    {
        return $destination !== null && $destination !== '' ? ' à '.$destination : '';
    }

    private function departmentSuffix(?string $department, ?string $destination): string
    {
        if ($department === null || $department === '') {
            return '';
        }

        if ($destination !== null && mb_strtolower(trim($department)) === mb_strtolower(trim($destination))) {
            return '';
        }

        return ' dans les '.$department;
    }

    private function mediaTitlePrefix(MediaType $mediaType, ?ImageType $imageType): string
    {
        if ($mediaType === MediaType::Video) {
            return 'Vidéo';
        }

        return match ($imageType) {
            ImageType::Degree360 => 'Vue 360°',
            ImageType::Degree180, ImageType::Panorama, ImageType::WideAngle => 'Panorama',
            default => 'Photo',
        };
    }

    private function mediaAltPrefix(MediaType $mediaType, ?ImageType $imageType): string
    {
        if ($mediaType === MediaType::Video) {
            return 'Vidéo';
        }

        return match ($imageType) {
            ImageType::Degree360 => 'Panorama 360°',
            ImageType::Degree180, ImageType::Panorama, ImageType::WideAngle => 'Panorama',
            default => 'Vue',
        };
    }

    private function filenameTypeToken(MediaType $mediaType, ?ImageType $imageType): string
    {
        if ($mediaType === MediaType::Video) {
            return 'video';
        }

        return match ($imageType) {
            ImageType::Degree360 => 'vue-360',
            ImageType::Degree180 => 'panorama-180',
            ImageType::Panorama, ImageType::WideAngle => 'panorama',
            default => 'photo',
        };
    }
}
