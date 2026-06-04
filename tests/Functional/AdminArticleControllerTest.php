<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Enum\ContentStatus;

final class AdminArticleControllerTest extends FunctionalTestCase
{
    public function testAccessRulesForArticleIndex(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/articles');
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('GET', '/admin/articles');
        self::assertResponseRedirects('/');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUnverifiedAdmin());
        $client->request('GET', '/admin/articles');
        self::assertResponseRedirects('/');
    }

    public function testVerifiedAdminCanOpenArticleIndexAndNewForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/articles');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nouvel article');
    }

    public function testCreateArticleRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/articles/new', [
            '_token' => 'bad-token',
            'title' => 'Article CSRF invalide',
            'content' => '<p>Contenu de test.</p>',
            'status' => ContentStatus::Draft->value,
        ]);

        self::assertResponseRedirects('/');
        self::assertNull($this->entityManager()->getRepository(Article::class)->findOneBy(['title' => 'Article CSRF invalide']));
    }

    public function testVerifiedAdminCanCreateMinimalDraftArticle(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $title = 'Article fonctionnel minimal '.$this->uniqueToken('article');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/articles/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => $title,
            'content' => '<p>Contenu article fonctionnel minimal.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->entityManager()->getRepository(Article::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Article::class, $article);
        self::assertSame($admin->getId(), $article->getAuthor()?->getId());
        self::assertSame(ContentStatus::Draft, $article->getStatus());
    }

    public function testEmptyArticleContentIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/articles/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Article sans contenu',
            'content' => '',
            'status' => ContentStatus::Draft->value,
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(Article::class)->findOneBy(['title' => 'Article sans contenu']));
    }

    public function testDeleteArticleRequiresValidCsrfAndArchivesWithValidToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $article = $this->createArticle();

        $client->request('POST', sprintf('/admin/articles/%d/delete', $article->getId()), ['_token' => 'bad-token']);
        self::assertResponseRedirects('/');
        $article = $this->refresh($article);
        self::assertSame(ContentStatus::Published, $article->getStatus());

        $crawler = $client->request('GET', '/admin/articles');
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/articles/%d/delete', $article->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/articles/%d/delete', $article->getId())),
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertSame(ContentStatus::Archived, $article->getStatus());
    }
}
