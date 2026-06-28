<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleAdminMediaInsertionScriptTest extends TestCase
{
    public function testArticleAdminUsesItsDedicatedLazyThumbnailAndInsertionControls(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2).'/templates/admin/articles/form.html.twig');
        $thumbnail = (string) file_get_contents(dirname(__DIR__, 2).'/templates/admin/articles/_media_thumbnail.html.twig');

        self::assertStringContainsString("admin/articles/_media_thumbnail.html.twig", $form);
        self::assertStringNotContainsString("public_detail/_responsive_image.html.twig", $form);
        self::assertStringContainsString('data-article-insert-media', $form);
        self::assertStringContainsString('data-article-cover-choice', $form);
        self::assertStringContainsString('data-article-delete-media', $form);
        self::assertStringNotContainsString('data-article-copy-media-code', $form);
        self::assertStringContainsString('loading="lazy"', $thumbnail);
        self::assertStringNotContainsString('srcset=', $thumbnail);
        self::assertStringNotContainsString('fetchpriority=', $thumbnail);
    }

    public function testArticleMediaInsertionRestoresTheEditorSelectionAndSynchronizesTheSource(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/assets/js/admin-article-form.js');

        self::assertStringContainsString("[data-article-insert-media]", $script);
        self::assertStringContainsString('button.dataset.articleInsertMedia', $script);
        self::assertStringContainsString("runCommand('insertText'", $script);
        self::assertStringContainsString('range.cloneRange()', $script);
        self::assertStringContainsString('selection?.addRange(range)', $script);
        self::assertStringContainsString('source.value = editor.innerHTML.trim()', $script);
        self::assertStringContainsString("form.addEventListener('submit', syncSource)", $script);
        self::assertStringNotContainsString('data-article-copy-media-code', $script);
        self::assertStringNotContainsString('navigator.clipboard', $script);
    }
}
