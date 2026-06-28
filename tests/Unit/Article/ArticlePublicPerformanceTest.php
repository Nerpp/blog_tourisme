<?php

namespace App\Tests\Unit\Article;

use PHPUnit\Framework\TestCase;

final class ArticlePublicPerformanceTest extends TestCase
{
    public function testArticleCssHasNoHomepageHeroAndCentersTheReadingLayout(): void
    {
        $css = file_get_contents(dirname(__DIR__, 3).'/assets/styles/article-show.css');
        self::assertIsString($css);

        self::assertStringNotContainsString('hero-sea-mountain-desktop.webp', $css);
        self::assertStringContainsString('--public-cover-image: none;', $css);
        self::assertStringContainsString('.public-detail-cover.article-show-cover', $css);
        self::assertMatchesRegularExpression(
            '/\.article-show-layout\s*\{[^}]*width: min\(1040px, 100%\);[^}]*margin-inline: auto;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.article-show-main \.article-content\s*\{[^}]*max-width: 820px;[^}]*margin-inline: auto;/s',
            $css,
        );
    }

    public function testArticleGalleryIsSplitFromCriticalArticleAssets(): void
    {
        $articleEntry = file_get_contents(dirname(__DIR__, 3).'/assets/entries/article-show.js');
        $galleryEntry = file_get_contents(dirname(__DIR__, 3).'/assets/entries/article-gallery.js');
        self::assertIsString($articleEntry);
        self::assertIsString($galleryEntry);

        self::assertStringNotContainsString('public-detail-gallery', $articleEntry);
        self::assertStringContainsString('article-gallery.css', $galleryEntry);
        self::assertStringContainsString('initPublicDetailGallery', $galleryEntry);
    }

    public function testHashedArticleWebpsHaveAnImmutableNginxCacheRule(): void
    {
        $nginx = file_get_contents(dirname(__DIR__, 3).'/docker/nginx/default.conf');
        self::assertIsString($nginx);

        self::assertStringContainsString('article_[a-f0-9]{24}_(inline|display|cover|source)', $nginx);
        self::assertMatchesRegularExpression(
            '/location[^\n]+article_\[a-f0-9\]\{24\}[^\{]+\{[^}]*max-age=31536000, immutable/s',
            $nginx,
        );
    }
}
