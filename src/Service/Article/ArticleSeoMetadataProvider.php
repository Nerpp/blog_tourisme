<?php

namespace App\Service\Article;

use App\Entity\Article;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ArticleSeoMetadataProvider
{
    private const int DESCRIPTION_MAX_LENGTH = 160;
    private const int DESCRIPTION_MIN_WORD_BOUNDARY = 120;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return array{title: string, description: string, canonical: string} */
    public function provide(Article $article): array
    {
        return [
            'title' => trim((string) $article->getTitle()),
            'description' => $this->descriptionFromExcerpt($article->getExcerpt()),
            'canonical' => $this->urlGenerator->generate(
                'app_article_show',
                ['slug' => (string) $article->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }

    private function descriptionFromExcerpt(?string $excerpt): string
    {
        $plainText = html_entity_decode((string) $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $plainText);
        $plainText = (string) preg_replace('/<[^>]+>/u', ' ', $plainText);
        $plainText = trim((string) preg_replace('/\s+/u', ' ', $plainText));
        $plainText = (string) preg_replace('/\s+([,.;:!?])/u', '$1', $plainText);

        if (mb_strlen($plainText) <= self::DESCRIPTION_MAX_LENGTH) {
            return $plainText;
        }

        $truncated = rtrim(mb_substr($plainText, 0, self::DESCRIPTION_MAX_LENGTH - 1));
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace >= self::DESCRIPTION_MIN_WORD_BOUNDARY) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B,.;:!?").'…';
    }
}
