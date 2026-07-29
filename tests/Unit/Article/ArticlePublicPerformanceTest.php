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
            '/\.article-show-main \.article-content\s*\{[^}]*display: flow-root;[^}]*max-width: 820px;[^}]*margin-inline: auto;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.article-show-main \.article-gallery-section\s*\{[^}]*clear: both;[^}]*margin-top: 42px;/s',
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

    public function testPublicGallerySizesMatchItsDesktopGridColumns(): void
    {
        $template = file_get_contents(dirname(__DIR__, 3).'/templates/public_detail/_image_gallery.html.twig');
        self::assertIsString($template);

        self::assertStringContainsString('(min-width: 1180px) 460px', $template);
        self::assertStringContainsString('(min-width: 900px) calc(50vw - 110px)', $template);
        self::assertStringContainsString('(min-width: 1180px) 220px', $template);
        self::assertStringContainsString('(min-width: 900px) calc(25vw - 60px)', $template);
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

    public function testApacheAndNginxCacheHashedAssetsWithoutFreezingUnversionedImages(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $apache = file_get_contents($projectDir.'/public/.htaccess');
        $nginx = file_get_contents($projectDir.'/docker/nginx/default.conf');
        self::assertIsString($apache);
        self::assertIsString($nginx);

        self::assertStringContainsString('max-age=31536000, immutable', $apache);
        self::assertStringContainsString('media_[a-f0-9]{20}', $apache);
        self::assertStringContainsString('article_[a-f0-9]{24}', $apache);
        self::assertStringContainsString('destination-card-placeholder(-[0-9]+)?', $apache);
        self::assertStringContainsString('max-age=604800, stale-while-revalidate=86400', $apache);
        self::assertStringContainsString('location ^~ /images/placeholders/', $nginx);
        self::assertMatchesRegularExpression(
            '/location \^~ \/images\/placeholders\/[^}]+expires 7d;[^}]+max-age=604800/s',
            $nginx,
        );
    }
}
