<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
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

    public function testArticleIndexDoesNotListDraftArticles(): void
    {
        $client = static::createClient();
        $published = $this->createArticle($this->createUser());
        $draft = $this->createArticle($this->createUser());
        $draft
            ->setTitle('Article brouillon invisible '.$this->uniqueToken('article'))
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $this->persistAndFlush($draft);

        $client->request('GET', '/articles');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $published->getTitle());
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testArticleIndexSearchFiltersCaseInsensitively(): void
    {
        $client = static::createClient();
        $token = $this->uniqueToken('roca');
        $matching = $this->createArticle($this->createUser());
        $matching->setTitle('Guide Roca public '.$token);
        $hiddenDraft = $this->createArticle($this->createUser());
        $hiddenDraft
            ->setTitle('Guide Roca brouillon '.$token)
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $unrelated = $this->createArticle($this->createUser());
        $unrelated->setTitle('Article hors recherche '.$this->uniqueToken('other'));
        $this->persistAndFlush($matching, $hiddenDraft, $unrelated);

        $client->request('GET', '/articles?q='.rawurlencode(strtoupper($token)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $matching->getTitle());
        self::assertStringNotContainsString((string) $hiddenDraft->getTitle(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString((string) $unrelated->getTitle(), (string) $client->getResponse()->getContent());
        self::assertSelectorExists('input[name="q"][value="'.strtoupper($token).'"]');
    }

    public function testArticleIndexSearchDisplaysEmptyState(): void
    {
        $client = static::createClient();

        $client->request('GET', '/articles?q='.rawurlencode($this->uniqueToken('no-result')));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucun article ne correspond à cette recherche.');
    }

    public function testArticleSuggestionsRequireTwoCharactersAndReturnLimitedPublicResults(): void
    {
        $client = static::createClient();
        $token = $this->uniqueToken('suggest');
        for ($index = 0; $index < 10; ++$index) {
            $article = $this->createArticle($this->createUser());
            $article->setTitle(sprintf('Suggestion article %s %02d', $token, $index));
            $this->persistAndFlush($article);
        }
        $draft = $this->createArticle($this->createUser());
        $draft
            ->setTitle('Suggestion article brouillon '.$token)
            ->setStatus(ContentStatus::Draft)
            ->setPublishedAt(null);
        $this->persistAndFlush($draft);

        $client->request('GET', '/articles/suggestions?q=a');
        self::assertResponseIsSuccessful();
        self::assertSame(['suggestions' => []], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));

        $client->request('GET', '/articles/suggestions?q='.rawurlencode($token));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['suggestions'] ?? null);
        self::assertLessThanOrEqual(8, count($payload['suggestions']));
        self::assertNotSame([], $payload['suggestions']);
        self::assertArrayHasKey('title', $payload['suggestions'][0]);
        self::assertArrayHasKey('url', $payload['suggestions'][0]);
        self::assertArrayHasKey('type', $payload['suggestions'][0]);
        self::assertStringStartsWith('/articles/', (string) $payload['suggestions'][0]['url']);
        self::assertStringNotContainsString((string) $draft->getTitle(), (string) $client->getResponse()->getContent());
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
