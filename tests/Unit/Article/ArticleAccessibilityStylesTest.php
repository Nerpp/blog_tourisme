<?php

namespace App\Tests\Unit\Article;

use PHPUnit\Framework\TestCase;

final class ArticleAccessibilityStylesTest extends TestCase
{
    public function testMutedArticleTextColorsMeetWcagAaOnTheirLightSurfaces(): void
    {
        $css = $this->articleCss();
        $cases = [
            ['.article-show-main .article-content-media figcaption', '#eef4ef'],
            ['.article-show-main .media-kicker', '#ffffff'],
            ['.article-show-side-card__kicker', '#ffffff'],
            ['.article-show-comments .comment-handle', '#f5f7f3'],
        ];

        foreach ($cases as [$selector, $background]) {
            $foreground = $this->colorForSelector($css, $selector);

            self::assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio($foreground, $background),
                sprintf('%s must keep a WCAG AA contrast ratio on %s.', $selector, $background),
            );
        }
    }

    public function testOnlyInlineArticleAndLoginCalloutLinksGetPersistentUnderlines(): void
    {
        $css = $this->articleCss();

        self::assertMatchesRegularExpression(
            '/\.article-show-main \.article-content a\s*\{[^}]*text-decoration-line: underline;[^}]*text-decoration-thickness: 0\.08em;[^}]*text-underline-offset: 0\.2em;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.article-show-comments \.login-callout a\s*\{[^}]*text-decoration-line: underline;[^}]*text-decoration-thickness: 0\.08em;[^}]*text-underline-offset: 0\.2em;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.article-show-main \.article-content a:hover,\s*\.article-show-main \.article-content a:focus-visible\s*\{[^}]*text-decoration-thickness: 0\.14em;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.article-show-comments \.login-callout a:hover,\s*\.article-show-comments \.login-callout a:focus-visible\s*\{[^}]*text-decoration-thickness: 0\.14em;/s',
            $css,
        );
    }

    private function articleCss(): string
    {
        $css = file_get_contents(dirname(__DIR__, 3).'/assets/styles/article-show.css');
        self::assertIsString($css);

        return $css;
    }

    private function colorForSelector(string $css, string $selector): string
    {
        self::assertSame(1, preg_match(
            '/'.preg_quote($selector, '/').'\s*\{[^}]*color:\s*(#[0-9a-f]{6});/i',
            $css,
            $matches,
        ));

        return strtolower($matches[1]);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = $this->relativeLuminance($foreground);
        $backgroundLuminance = $this->relativeLuminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private function relativeLuminance(string $color): float
    {
        $channels = [
            hexdec(substr($color, 1, 2)) / 255,
            hexdec(substr($color, 3, 2)) / 255,
            hexdec(substr($color, 5, 2)) / 255,
        ];
        $channels = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
