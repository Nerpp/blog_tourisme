<?php

namespace App\Service;

use App\Entity\MediaAsset;
use App\Enum\VideoType;

final readonly class VideoEmbedUrlResolver
{
    public function resolve(MediaAsset $media): ?string
    {
        $externalUrl = $media->getExternalUrl();

        if (!$externalUrl || !$media->getVideoType()) {
            return null;
        }

        return match ($media->getVideoType()) {
            VideoType::Youtube => $this->resolveYoutubeUrl($externalUrl),
            VideoType::Vimeo => $this->resolveVimeoUrl($externalUrl),
            VideoType::Dailymotion => $this->resolveDailymotionUrl($externalUrl),
            default => null,
        };
    }

    private function resolveYoutubeUrl(string $url): ?string
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

        $id = $this->cleanVideoId($id);

        if ($id === null) {
            return null;
        }

        return sprintf('https://www.youtube-nocookie.com/embed/%s', $id);
    }

    private function resolveVimeoUrl(string $url): ?string
    {
        if (!preg_match('#vimeo\.com/(?:video/)?([0-9]+)#', $url, $matches)) {
            return null;
        }

        return sprintf('https://player.vimeo.com/video/%s', $matches[1]);
    }

    private function resolveDailymotionUrl(string $url): ?string
    {
        if (!preg_match('#(?:dailymotion\.com/video/|dai\.ly/)([A-Za-z0-9]+)#', $url, $matches)) {
            return null;
        }

        return sprintf('https://www.dailymotion.com/embed/video/%s', $matches[1]);
    }

    private function cleanVideoId(?string $id): ?string
    {
        if (!$id) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) ? $id : null;
    }
}