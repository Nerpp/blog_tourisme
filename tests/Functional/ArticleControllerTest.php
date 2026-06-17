<?php

namespace App\Tests\Functional;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ArticleControllerTest extends FunctionalTestCase
{
    public function testArticleIndexListsPublishedArticles(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());

        $client->request('GET', '/articles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Articles');
        self::assertSelectorTextContains('body', (string) $article->getTitle());
    }

    public function testPublishedArticleIsAccessibleWithPopularCommentSort(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());
        $comment = $this->createComment($this->createUser(), $article);

        $client->request('GET', sprintf('/articles/%s?comments_sort=popular', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $article->getTitle());
        self::assertSelectorTextContains('body', (string) $comment->getContent());
    }

    public function testInvalidCommentSortFallsBackWithoutServerError(): void
    {
        $client = static::createClient();
        $article = $this->createArticle($this->createUser());

        $client->request('GET', sprintf('/articles/%s?comments_sort=unexpected', $article->getSlug()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $article->getTitle());
    }

    public function testUnknownArticleReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $this->expectException(NotFoundHttpException::class);

        $client->request('GET', '/articles/article-fonctionnel-inconnu');
    }
}
