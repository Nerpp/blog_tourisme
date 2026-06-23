<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleHike;
use App\Entity\ArticleMedia;
use App\Entity\MediaAsset;
use App\Enum\ContentStatus;
use App\Enum\MediaRole;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    public function testArticleIndexDisplaysExistingArticle(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/articles');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $article->getTitle(), (string) $client->getResponse()->getContent());
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

    public function testVerifiedAdminCanOpenArticleEditForm(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Modifier l’article');
        self::assertStringContainsString((string) $article->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testEditingUnknownArticleReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', '/admin/articles/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEditArticleRequiresValidCsrf(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $previousTitle = $article->getTitle();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => 'bad-token',
            'title' => 'Article modifié avec mauvais CSRF',
            'content' => '<p>Contenu de modification invalide.</p>',
            'status' => ContentStatus::Draft->value,
        ]);

        self::assertResponseRedirects('/');
        $article = $this->refresh($article);
        self::assertSame($previousTitle, $article->getTitle());
    }

    public function testInvalidEditKeepsArticleUnchanged(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $previousTitle = $article->getTitle();
        $previousContent = $article->getContent();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => 'Article édition invalide',
            'content' => '',
            'status' => ContentStatus::Draft->value,
        ]);

        self::assertResponseIsSuccessful();
        $article = $this->refresh($article);
        self::assertSame($previousTitle, $article->getTitle());
        self::assertSame($previousContent, $article->getContent());
    }

    public function testVerifiedAdminCanEditDraftArticleAndSwitchLinkedContent(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $duplicateTitle = 'Article destination miroir '.$this->uniqueToken('slug');
        $existingArticle = $this->createDraftArticle($duplicateTitle);
        $article = $this->createDraftArticle();
        $hike = $this->createPublishedHike($admin);
        $cityVisit = $this->createPublishedCityVisit($admin);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $existingArticle->getTitle(),
            'excerpt' => 'Résumé édité',
            'content' => '<h2>Contenu édité</h2><p>Texte fonctionnel suffisant.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'hike',
            'linkedHike' => $hike->getId(),
            'articleRole' => 'history',
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertSame($existingArticle->getTitle(), $article->getTitle());
        self::assertSame($this->slugFromTitle($duplicateTitle).'-2', $article->getSlug());
        self::assertCount(1, $article->getHikeLinks());
        $hikeLink = $article->getHikeLinks()->first();
        self::assertInstanceOf(ArticleHike::class, $hikeLink);
        self::assertSame($hike->getId(), $hikeLink->getHikeDraft()?->getId());
        self::assertSame('history', $hikeLink->getRole());
        self::assertCount(0, $article->getCityVisitLinks());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'excerpt' => 'Résumé édité vers visite',
            'content' => '<p>Contenu édité vers une visite de ville.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'city_visit',
            'linkedCityVisit' => $cityVisit->getId(),
            'articleRole' => 'practical',
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertCount(0, $article->getHikeLinks());
        self::assertCount(1, $article->getCityVisitLinks());
        $cityVisitLink = $article->getCityVisitLinks()->first();
        self::assertInstanceOf(ArticleCityVisit::class, $cityVisitLink);
        self::assertSame($cityVisit->getId(), $cityVisitLink->getCityVisitDraft()?->getId());
        self::assertSame('practical', $cityVisitLink->getRole());
    }

    public function testVerifiedAdminCanPromoteAndRemoveExistingArticleMedia(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $coverMedia = $this->createImageMedia('Couverture article');
        $galleryMedia = $this->createImageMedia('Galerie article');
        $coverLink = $this->linkArticleMedia($article, $coverMedia, MediaRole::Cover, 0);
        $galleryLink = $this->linkArticleMedia($article, $galleryMedia, MediaRole::Gallery, 1);
        $article->setFeaturedImage($coverMedia);
        $this->persistAndFlush($article);
        $coverMediaId = $coverMedia->getId();
        $coverLinkId = $coverLink->getId();
        self::assertNotNull($coverMediaId);
        self::assertNotNull($coverLinkId);
        self::assertNotNull($galleryLink->getId());
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'content' => (string) $article->getContent(),
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
            'promoteCoverMedia' => $galleryLink->getId(),
            'removeMediaLinks' => [$coverLinkId],
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertSame($galleryMedia->getId(), $article->getFeaturedImage()?->getId());
        $remainingMediaLink = $article->getMediaLinks()->first();
        self::assertInstanceOf(ArticleMedia::class, $remainingMediaLink);
        self::assertSame(MediaRole::Cover, $remainingMediaLink->getRole());
        self::assertNull($this->entityManager()->getRepository(ArticleMedia::class)->find($coverLinkId));
        self::assertNull($this->entityManager()->getRepository(MediaAsset::class)->find($coverMediaId));
    }

    public function testStructuredMediaIdentifiersDoNotRemoveOrPromoteArticleMedia(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $coverMedia = $this->createImageMedia('Couverture article conservée');
        $galleryMedia = $this->createImageMedia('Galerie article conservée');
        $coverLink = $this->linkArticleMedia($article, $coverMedia, MediaRole::Cover, 0);
        $galleryLink = $this->linkArticleMedia($article, $galleryMedia, MediaRole::Gallery, 1);
        $article->setFeaturedImage($coverMedia);
        $this->persistAndFlush($article);
        $articleId = $article->getId();
        $coverLinkId = $coverLink->getId();
        $galleryLinkId = $galleryLink->getId();
        self::assertIsInt($articleId);
        self::assertIsInt($coverLinkId);
        self::assertIsInt($galleryLinkId);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $articleId));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/articles/%d/edit', $articleId), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'content' => (string) $article->getContent(),
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
            'promoteCoverMedia' => [$galleryLinkId],
            'removeMediaLinks' => [[$coverLinkId]],
        ]);

        self::assertResponseStatusCodeSame(400);
        $this->entityManager()->clear();
        $storedArticle = $this->entityManager()->find(Article::class, $articleId);
        self::assertInstanceOf(Article::class, $storedArticle);
        self::assertSame($coverMedia->getId(), $storedArticle->getFeaturedImage()?->getId());
        self::assertCount(2, $storedArticle->getMediaLinks());
        self::assertNotNull($this->entityManager()->find(ArticleMedia::class, $coverLinkId));
        self::assertNotNull($this->entityManager()->find(ArticleMedia::class, $galleryLinkId));
    }

    public function testInvalidArticleImageUploadIsIgnored(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();
        $invalidImagePath = $this->createInvalidUploadFile();

        try {
            $client->request(
                'POST',
                sprintf('/admin/articles/%d/edit', $article->getId()),
                [
                    '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
                    'title' => (string) $article->getTitle(),
                    'content' => (string) $article->getContent(),
                    'status' => ContentStatus::Draft->value,
                    'linkedContentType' => 'none',
                    'articleRole' => 'related',
                ],
                [
                    'coverImage' => new UploadedFile($invalidImagePath, 'article-invalid.gif', 'image/gif', null, true),
                ],
            );
        } finally {
            if (is_file($invalidImagePath)) {
                unlink($invalidImagePath);
            }
        }

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertNull($article->getFeaturedImage());
        self::assertCount(0, $article->getMediaLinks());
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

    public function testDeletingUnknownArticleReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('POST', '/admin/articles/999999/delete', ['_token' => 'token-unused']);

        self::assertResponseStatusCodeSame(404);
    }

    private function createDraftArticle(?string $title = null): Article
    {
        $token = $this->uniqueToken('article');
        $title ??= 'Article brouillon '.$token;
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        $article = (new Article())
            ->setTitle($title)
            ->setSlug($slug)
            ->setExcerpt('Résumé de test '.$token)
            ->setContent('<p>Contenu de brouillon fonctionnel.</p>')
            ->setStatus(ContentStatus::Draft);

        $this->persistAndFlush($article);

        return $article;
    }

    private function slugFromTitle(string $title): string
    {
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    }

    private function createInvalidUploadFile(): string
    {
        $path = sys_get_temp_dir().'/article-invalid-'.$this->uniqueToken('upload').'.gif';
        file_put_contents($path, 'not an image');

        return $path;
    }

    private function linkArticleMedia(Article $article, MediaAsset $media, MediaRole $role, int $position): ArticleMedia
    {
        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $article->getMediaLinks()->add($link);
        $media->getArticleLinks()->add($link);

        $this->persistAndFlush($link, $article, $media);

        return $link;
    }
}
