<?php

namespace App\Service\Instagram;

use App\Entity\MediaAsset;
use App\Service\Seo\PublicUrlGenerator;

final readonly class MediaPublicUrlResolver
{
    /** @var list<string> */
    private const VARIANT_SIZES = [
        'large',
        'medium',
        'content960',
        'mobile',
        'content768',
        'content640',
        'thumbnail480',
        'thumb',
        'thumbnail320',
    ];

    public function __construct(private PublicUrlGenerator $publicUrlGenerator)
    {
    }

    public function resolve(MediaAsset $mediaAsset): ?string
    {
        foreach ($this->candidates($mediaAsset) as $candidate) {
            $url = $this->toDownloadableUrl($candidate);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function candidates(MediaAsset $mediaAsset): array
    {
        $candidates = [];
        $variants = $mediaAsset->getVariants();

        foreach (self::VARIANT_SIZES as $size) {
            $variant = is_array($variants) && isset($variants[$size]) && is_array($variants[$size])
                ? $variants[$size]
                : [];

            foreach (['webp', 'fallback'] as $format) {
                $path = $variant[$format] ?? null;
                if (is_string($path) && trim($path) !== '') {
                    $candidates[] = $path;
                }
            }
        }

        foreach ([$mediaAsset->getFilePath(), $mediaAsset->getExternalUrl(), $mediaAsset->getThumbnailPath()] as $path) {
            if (is_string($path) && trim($path) !== '') {
                $candidates[] = $path;
            }
        }

        return $candidates;
    }

    private function toDownloadableUrl(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $candidate) === 1 && preg_match('#^https?://#i', $candidate) !== 1) {
            return null;
        }

        $url = $this->publicUrlGenerator->absolute($candidate);
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if ($path === '' || str_contains(strtolower($path), '/images/placeholders/')) {
            return null;
        }

        return $url;
    }
}
