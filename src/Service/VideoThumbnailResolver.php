<?php

namespace App\Service;

final readonly class VideoThumbnailResolver
{
    public function getThumbnailUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $youtubeId = $this->extractYoutubeId($url);

        return $youtubeId !== null ? sprintf('https://img.youtube.com/vi/%s/hqdefault.jpg', $youtubeId) : null;
    }

    public function extractYoutubeId(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $id = null;

        if ($host === 'youtu.be') {
            $id = explode('/', $path)[0];
        }

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === 'watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^(embed|shorts|live)/([^/?]+)#', $path, $matches)) {
                $id = $matches[2];
            }
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) === 1 ? $id : null;
    }
}
