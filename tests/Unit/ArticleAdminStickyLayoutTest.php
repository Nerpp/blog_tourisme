<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleAdminStickyLayoutTest extends TestCase
{
    public function testArticleSidebarIsStickyOnlyAboveTheAdminMobileBreakpoint(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/assets/styles/admin.css');
        self::assertIsString($css);

        $pattern = '/@media \(min-width: 821px\)\s*\{\s*\.article-admin-sidebar--sticky\s*\{(?P<body>[^}]*)}/s';
        self::assertMatchesRegularExpression($pattern, $css);
        preg_match($pattern, $css, $matches);
        self::assertArrayHasKey('body', $matches);

        $stickyRule = $matches['body'];
        self::assertStringContainsString('position: sticky;', $stickyRule);
        self::assertStringContainsString('top: 24px;', $stickyRule);
        self::assertStringContainsString('max-height: calc(100vh - 48px);', $stickyRule);
        self::assertStringContainsString('overflow-y: auto;', $stickyRule);
        self::assertStringNotContainsString('fixed', $stickyRule);
        self::assertStringNotContainsString('studio', $stickyRule);
        self::assertSame(1, substr_count($css, '.article-admin-sidebar--sticky'));
    }
}
