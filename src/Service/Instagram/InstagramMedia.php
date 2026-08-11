<?php

namespace App\Service\Instagram;

use App\Entity\MediaAsset;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class InstagramMedia
{
    public ?int $mediaId;

    public string $url;

    public function __construct(
        public MediaAsset $mediaAsset,
        string $url,
    ) {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
        ) {
            throw new \InvalidArgumentException('Une image Instagram doit utiliser une URL HTTPS publique absolue.');
        }

        $this->mediaId = $mediaAsset->getId();
        $this->url = $url;
    }
}
