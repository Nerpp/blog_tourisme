<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleAdminMediaCopyScriptTest extends TestCase
{
    public function testArticleMediaCopyUsesClipboardFallbackAndDoesNotInsertIntoEditor(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/assets/js/admin-article-form.js');

        self::assertStringContainsString('navigator.clipboard.writeText', $script);
        self::assertStringContainsString('document.createElement(\'textarea\')', $script);
        self::assertStringContainsString('document.execCommand(\'copy\')', $script);
        self::assertStringContainsString('Code copié', $script);
        self::assertStringContainsString('Copie impossible', $script);
        self::assertStringContainsString('window.setTimeout', $script);
        self::assertStringContainsString('data-article-copy-media-code', $script);
        self::assertStringNotContainsString('articleInsertMedia', $script);
        self::assertStringNotContainsString('insertText', $script);
    }
}
