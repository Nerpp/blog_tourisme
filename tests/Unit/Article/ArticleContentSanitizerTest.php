<?php

namespace App\Tests\Unit\Article;

use App\Service\Article\ArticleContentSanitizer;
use PHPUnit\Framework\TestCase;

final class ArticleContentSanitizerTest extends TestCase
{
    public function testRemovesDangerousTagsAndEventAttributes(): void
    {
        $html = '<p onclick="alert(1)">Texte <strong>important</strong><script>alert(1)</script><img src=x onerror=alert(1)> normal</p>';

        self::assertSame(
            '<p>Texte <strong>important</strong> normal</p>',
            $this->sanitizer()->sanitize($html),
        );
    }

    public function testDropsUnsafeLinksButKeepsTheirText(): void
    {
        self::assertSame(
            '<p>Lien piege et <a href="https://example.test" rel="noopener noreferrer">lien sur</a></p>',
            $this->sanitizer()->sanitize('<p><a href="java script:alert(1)">Lien piege</a> et <a href="https://example.test">lien sur</a></p>'),
        );
    }

    public function testKeepsAllowedStructureAndNormalizesLegacyInlineTags(): void
    {
        $html = '<h2>Titre</h2><ul><li><b>Point</b> <i>detail</i></li></ul><blockquote>Citation</blockquote>';

        self::assertSame(
            '<h2>Titre</h2><ul><li><strong>Point</strong> <em>detail</em></li></ul><blockquote>Citation</blockquote>',
            $this->sanitizer()->sanitize($html),
        );
    }

    public function testPlainTextBecomesParagraphsWithoutDoubleEscaping(): void
    {
        self::assertSame(
            '<p>Ligne propre<br>suite</p><p>Second paragraphe &amp; details</p>',
            $this->sanitizer()->sanitize("Ligne propre\nsuite\n\nSecond paragraphe & details"),
        );
    }

    public function testHandlesEmptyAndMalformedHtml(): void
    {
        self::assertSame('', $this->sanitizer()->sanitize(null));
        self::assertSame('<p><strong>Texte</strong></p>', $this->sanitizer()->sanitize('<p><strong>Texte</p>'));
    }

    private function sanitizer(): ArticleContentSanitizer
    {
        return new ArticleContentSanitizer();
    }
}
