<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleHike;
use App\Entity\ArticleMedia;
use App\Entity\Category;
use App\Entity\Comment;
use App\Entity\MediaAsset;
use App\Entity\PublicationNotificationLog;
use App\Enum\CategoryType;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Tests\Support\TestImageFactory;
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

    public function testArticleIndexHasNoStudioPlaceholderAndExposesArchiveAndDeleteActions(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/articles');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Studio non disponible', $crawler->text());
        self::assertSame(0, $crawler->filter('a[href*="/admin/articles/"][href*="/studio"]')->count());

        $archivePath = sprintf('/admin/articles/%d/archive', $article->getId());
        $deletePath = sprintf('/admin/articles/%d/delete', $article->getId());
        self::assertSame('Archiver', trim($crawler->filter(sprintf('form[action="%s"] button', $archivePath))->text()));
        self::assertSame('Supprimer', trim($crawler->filter(sprintf('form[action="%s"] button', $deletePath))->text()));

        $deleteConfirmation = (string) $crawler->filter(sprintf('form[action="%s"]', $deletePath))->attr('onsubmit');
        self::assertSame(1, preg_match("/^return confirm\\('(.*)'\\);$/", $deleteConfirmation, $confirmationMatches));
        $confirmationText = json_decode('"'.$confirmationMatches[1].'"', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($confirmationText);
        self::assertStringContainsString((string) $article->getTitle(), $confirmationText);
        self::assertStringContainsString('Cette action est irréversible', $confirmationText);
        self::assertStringContainsString('Le contenu de l’article sera supprimé', $confirmationText);
        self::assertStringContainsString('illustrations propres', $confirmationText);
        self::assertStringContainsString('médias partagés', $confirmationText);
    }

    public function testHikeAndCityVisitStudiosRemainAvailable(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/studio');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a[href="/admin/field-tools/hikes"]')->count());
        self::assertSame(1, $crawler->filter('a[href="/admin/field-tools/city-visits"]')->count());
    }

    public function testArticleFormDisplaysConfiguredArticleCategories(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/articles/new');

        self::assertResponseIsSuccessful();
        $categoryLabels = $crawler->filter('#article-category option')->each(
            static fn ($option): string => trim($option->text()),
        );
        self::assertContains('Non renseignée', $categoryLabels);
        self::assertContains('Conseil voyage', $categoryLabels);
        self::assertContains('Culture', $categoryLabels);
        self::assertContains('Histoire locale', $categoryLabels);
        self::assertContains('Infos pratiques', $categoryLabels);
        self::assertContains('Itinéraire', $categoryLabels);
        self::assertContains('Légendes et traditions', $categoryLabels);
        self::assertContains('Nature', $categoryLabels);
        self::assertContains('Patrimoine', $categoryLabels);
    }

    public function testArticleFormGetRequestsNeverCreateMissingCategories(): void
    {
        $client = static::createClient();
        $categoryRepository = $this->entityManager()->getRepository(Category::class);
        $missingCategory = $categoryRepository->findOneBy(['slug' => 'histoire-locale']);
        self::assertInstanceOf(Category::class, $missingCategory);
        $definition = [
            'name' => (string) $missingCategory->getName(),
            'slug' => (string) $missingCategory->getSlug(),
            'type' => $missingCategory->getType(),
            'description' => $missingCategory->getDescription(),
        ];
        $this->entityManager()->remove($missingCategory);
        $this->entityManager()->flush();
        $categoryCount = $categoryRepository->count([]);
        $article = $this->createDraftArticle();
        $client->loginUser($this->createVerifiedAdmin());

        try {
            $client->request('GET', '/admin/articles/new');
            self::assertResponseIsSuccessful();
            self::assertSame($categoryCount, $this->entityManager()->getRepository(Category::class)->count([]));
            self::assertNull($this->entityManager()->getRepository(Category::class)->findOneBy(['slug' => $definition['slug']]));

            $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
            self::assertResponseIsSuccessful();
            self::assertSame($categoryCount, $this->entityManager()->getRepository(Category::class)->count([]));
            self::assertNull($this->entityManager()->getRepository(Category::class)->findOneBy(['slug' => $definition['slug']]));
        } finally {
            if (!$this->entityManager()->getRepository(Category::class)->findOneBy(['slug' => $definition['slug']]) instanceof Category) {
                $restoredCategory = (new Category())
                    ->setName($definition['name'])
                    ->setSlug($definition['slug'])
                    ->setType($definition['type'])
                    ->setDescription($definition['description']);
                $this->persistAndFlush($restoredCategory);
            }
        }
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

    public function testVerifiedAdminCanCreateAndEditArticleWithCategory(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $firstCategory = $this->createCategory(CategoryType::Article);
        $secondCategory = $this->createCategory(CategoryType::Article);
        $title = 'Article catégorisé '.$this->uniqueToken('article');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/articles/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
            'title' => $title,
            'category' => (string) $firstCategory->getId(),
            'content' => '<p>Contenu article avec catégorie.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->entityManager()->getRepository(Article::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Article::class, $article);
        self::assertSame($firstCategory->getId(), $article->getCategory()?->getId());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame((string) $firstCategory->getId(), $crawler->filter('#article-category option[selected]')->attr('value'));

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'category' => (string) $secondCategory->getId(),
            'content' => '<p>Contenu article avec catégorie modifiée.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertSame($secondCategory->getId(), $article->getCategory()?->getId());
    }

    public function testVerifiedAdminCanCreateArticleWithCoverAndGalleryImages(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP support is required for article media optimization.');
        }

        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $title = 'Article illustré '.$this->uniqueToken('article');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();

        $coverPath = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 2200, 1240, 'article-cover.jpg');
        $galleryPath = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 1800, 1200, 'article-gallery.jpg');

        $client->request(
            'POST',
            '/admin/articles/new',
            [
                '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
                '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
                'title' => $title,
                'content' => '<p>Contenu article fonctionnel avec illustrations.</p>',
                'status' => ContentStatus::Published->value,
                'linkedContentType' => 'none',
                'articleRole' => 'related',
                'newCoverImageIndex' => '0',
            ],
            [
                'galleryImages' => [
                    TestImageFactory::createUploadedFile($coverPath, 'Couverture article.jpg', 'image/jpeg'),
                    TestImageFactory::createUploadedFile($galleryPath, 'Galerie article.jpg', 'image/jpeg'),
                ],
            ],
        );

        self::assertResponseRedirects('/admin/articles');
        $article = $this->entityManager()->getRepository(Article::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Article::class, $article);
        self::assertCount(2, $article->getMediaLinks());

        $coverLinks = $article->getMediaLinks()->filter(fn (ArticleMedia $link): bool => $link->getRole() === MediaRole::Cover);
        $galleryLinks = $article->getMediaLinks()->filter(fn (ArticleMedia $link): bool => $link->getRole() === MediaRole::Gallery);
        self::assertCount(1, $coverLinks);
        self::assertCount(1, $galleryLinks);

        $coverMedia = $article->getFeaturedImage();
        self::assertInstanceOf(MediaAsset::class, $coverMedia);
        self::assertSame($coverMedia->getId(), $coverLinks->first()->getMediaAsset()?->getId());
        self::assertNull($coverMedia->getFilePath());
        self::assertIsArray($coverMedia->getVariants());
        self::assertSame(600, $coverMedia->getVariants()['thumb']['width'] ?? null);
        self::assertSame(1600, $coverMedia->getVariants()['medium']['width'] ?? null);
        self::assertSame($coverMedia->getVariants()['thumb']['webp'] ?? null, $coverMedia->getThumbnailPath());
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

    public function testInvalidArticleCreateKeepsSelectedCategory(): void
    {
        $client = static::createClient();
        $category = $this->createCategory(CategoryType::Article);
        $client->loginUser($this->createVerifiedAdmin());
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();

        $crawler = $client->request('POST', '/admin/articles/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
            'title' => 'Article invalide avec catégorie',
            'category' => (string) $category->getId(),
            'content' => '',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame((string) $category->getId(), $crawler->filter('#article-category option[selected]')->attr('value'));
        self::assertNull($this->entityManager()->getRepository(Article::class)->findOneBy(['title' => 'Article invalide avec catégorie']));
    }

    public function testEditingArticleWithoutCategoryFieldDoesNotResetCurrentCategory(): void
    {
        $client = static::createClient();
        $category = $this->createCategory(CategoryType::Article);
        $article = $this->createDraftArticle();
        $article->setCategory($category);
        $this->persistAndFlush($article);
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
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertSame($category->getId(), $article->getCategory()?->getId());
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

    public function testReplacingCoverKeepsPreviousCoverAttachedAsGallery(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP support is required for article media optimization.');
        }

        $client = static::createClient();
        $article = $this->createDraftArticle();
        $previousCover = $this->createImageMedia('Ancienne couverture article');
        $this->linkArticleMedia($article, $previousCover, MediaRole::Cover, 0);
        $article->setFeaturedImage($previousCover);
        $this->persistAndFlush($article);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();
        $newCoverPath = TestImageFactory::createJpeg(TestImageFactory::testMediaDirectory(), 1800, 1000, 'new-article-cover.jpg');

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
                'newCoverImageIndex' => '0',
            ],
            [
                'galleryImages' => [
                    TestImageFactory::createUploadedFile($newCoverPath, 'Nouvelle couverture.jpg', 'image/jpeg'),
                ],
            ],
        );

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertCount(2, $article->getMediaLinks());
        self::assertNotSame($previousCover->getId(), $article->getFeaturedImage()?->getId());

        $coverLinks = $article->getMediaLinks()->filter(fn (ArticleMedia $link): bool => $link->getRole() === MediaRole::Cover);
        $previousCoverLinks = $article->getMediaLinks()->filter(
            fn (ArticleMedia $link): bool => $link->getMediaAsset()?->getId() === $previousCover->getId(),
        );
        self::assertCount(1, $coverLinks);
        self::assertCount(1, $previousCoverLinks);
        self::assertSame(MediaRole::Gallery, $previousCoverLinks->first()->getRole());
    }

    public function testDeletingArticleGalleryImageRemovesLinkContentReferenceMediaAndFiles(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $media = $this->createStoredArticleImageMedia('Image galerie à supprimer');
        $link = $this->linkArticleMedia($article, $media, MediaRole::Gallery, 0);
        $article->setContent(sprintf('<p>Avant [[media:%d]] après.</p><p>[[media:%d]]</p>', $media->getId(), $media->getId()));
        $this->persistAndFlush($article);
        $files = $this->mediaFiles($media);
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
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
            'removeMediaLinks' => [$link->getId()],
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        $storedArticle = $this->entityManager()->find(Article::class, $article->getId());
        self::assertInstanceOf(Article::class, $storedArticle);
        self::assertStringNotContainsString(sprintf('[[media:%d]]', $media->getId()), (string) $storedArticle->getContent());
        self::assertStringContainsString('<p>Avant après.</p>', (string) $storedArticle->getContent());
        self::assertStringNotContainsString('<p></p>', (string) $storedArticle->getContent());
        self::assertNull($this->entityManager()->find(ArticleMedia::class, $link->getId()));
        self::assertNull($this->entityManager()->find(MediaAsset::class, $media->getId()));
        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }
    }

    public function testDeletingArticleCoverClearsFeaturedImageAndPublicCover(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $article->setStatus(ContentStatus::Published)->setPublishedAt(new \DateTimeImmutable('-1 hour'));
        $media = $this->createStoredArticleImageMedia('Image couverture à supprimer');
        $link = $this->linkArticleMedia($article, $media, MediaRole::Cover, 0);
        $article->setFeaturedImage($media);
        $this->persistAndFlush($article);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'content' => (string) $article->getContent(),
            'status' => ContentStatus::Published->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
            'removeMediaLinks' => [$link->getId()],
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        $storedArticle = $this->entityManager()->find(Article::class, $article->getId());
        self::assertInstanceOf(Article::class, $storedArticle);
        self::assertNull($storedArticle->getFeaturedImage());
        self::assertNull($this->entityManager()->find(MediaAsset::class, $media->getId()));

        $client->request('GET', sprintf('/articles/%s', $storedArticle->getSlug()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) ($media->getVariants()['medium']['webp'] ?? ''), (string) $client->getResponse()->getContent());
    }

    public function testLegacyFeaturedImageIsManagedAndDeletedFromArticleImagesSection(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $media = $this->createStoredArticleImageMedia('Ancienne couverture sans lien média');
        $article->setFeaturedImage($media);
        $article->setContent(sprintf('<p>Avant [[media:%d]] après.</p>', $media->getId()));
        $this->persistAndFlush($article);
        $files = $this->mediaFiles($media);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter(sprintf('input[name="removeFeaturedImage"][value="%d"]', $media->getId()))->count());

        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'content' => (string) $article->getContent(),
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
            'removeFeaturedImage' => (string) $media->getId(),
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        $storedArticle = $this->entityManager()->find(Article::class, $article->getId());
        self::assertInstanceOf(Article::class, $storedArticle);
        self::assertNull($storedArticle->getFeaturedImage());
        self::assertStringNotContainsString(sprintf('[[media:%d]]', $media->getId()), (string) $storedArticle->getContent());
        self::assertNull($this->entityManager()->find(MediaAsset::class, $media->getId()));
        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }
    }

    public function testDeletingSharedArticleImageOnlyRemovesCurrentArticleLink(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $otherArticle = $this->createDraftArticle('Autre article partage média '.$this->uniqueToken('article'));
        $media = $this->createStoredArticleImageMedia('Image article partagée');
        $link = $this->linkArticleMedia($article, $media, MediaRole::Gallery, 0);
        $otherLink = $this->linkArticleMedia($otherArticle, $media, MediaRole::Gallery, 0);
        $files = $this->mediaFiles($media);
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
            'removeMediaLinks' => [$link->getId()],
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(ArticleMedia::class, $link->getId()));
        self::assertNotNull($this->entityManager()->find(ArticleMedia::class, $otherLink->getId()));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $media->getId()));
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
    }

    public function testDeletingArticleImagePreservesMediaUsedByHikeAndCityVisit(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $article = $this->createDraftArticle();
        $hike = $this->createPublishedHike($admin);
        $cityVisit = $this->createPublishedCityVisit($admin);
        $media = $this->createStoredArticleImageMedia('Image partagée avec contenus touristiques');
        $articleLink = $this->linkArticleMedia($article, $media, MediaRole::Gallery, 0);
        $hikeLink = $this->linkHikeMedia($hike, $media);
        $cityVisitLink = $this->linkCityVisitMedia($cityVisit, $media);
        $files = $this->mediaFiles($media);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/articles/%d/edit', $article->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $article->getTitle(),
            'content' => (string) $article->getContent(),
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
            'removeMediaLinks' => [$articleLink->getId()],
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(ArticleMedia::class, $articleLink->getId()));
        self::assertNotNull($this->entityManager()->find($hikeLink::class, $hikeLink->getId()));
        self::assertNotNull($this->entityManager()->find($cityVisitLink::class, $cityVisitLink->getId()));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $media->getId()));
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
    }

    public function testDeletingArticleImagePreservesMediaReferencedByAnotherArticleContent(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $otherArticle = $this->createDraftArticle('Autre article avec référence média '.$this->uniqueToken('article'));
        $media = $this->createStoredArticleImageMedia('Image référencée dans un autre contenu');
        $articleLink = $this->linkArticleMedia($article, $media, MediaRole::Gallery, 0);
        $otherArticle->setContent(sprintf('<p>Contenu avec [[media:%d]].</p>', $media->getId()));
        $this->persistAndFlush($otherArticle);
        $files = $this->mediaFiles($media);
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
            'removeMediaLinks' => [$articleLink->getId()],
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $media->getId()));
        self::assertStringContainsString(
            sprintf('[[media:%d]]', $media->getId()),
            (string) $this->entityManager()->find(Article::class, $otherArticle->getId())?->getContent(),
        );
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
    }

    public function testArticleAdminDisplaysExistingMediaWithLightweightThumbnails(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $media = $this->createImageMedia('Image admin article légère');
        $media
            ->setThumbnailPath('/uploads/media/variants/article-thumb.webp')
            ->setVariants([
                'thumb' => ['webp' => '/uploads/media/variants/article-thumb.webp', 'width' => 600, 'height' => 338],
                'mobile' => ['webp' => '/uploads/media/variants/article-mobile.webp', 'width' => 960, 'height' => 540],
                'medium' => ['webp' => '/uploads/media/variants/article-medium.webp', 'width' => 1600, 'height' => 900],
                'large' => ['webp' => '/uploads/media/variants/article-large.webp', 'width' => 1920, 'height' => 1080],
            ]);
        $this->linkArticleMedia($article, $media, MediaRole::Cover, 0);
        $article->setFeaturedImage($media);
        $this->persistAndFlush($article, $media);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', sprintf('/admin/articles/%d/edit', $article->getId()));

        self::assertResponseIsSuccessful();
        $cardImage = $crawler->filter('img.article-admin-media-item__image')->first();
        self::assertSame(1, $cardImage->count());
        self::assertSame('/uploads/media/variants/article-thumb.webp', $cardImage->attr('src'));
        self::assertNull($cardImage->attr('srcset'));
        self::assertNull($cardImage->attr('sizes'));
        self::assertSame('lazy', $cardImage->attr('loading'));
        self::assertSame('async', $cardImage->attr('decoding'));
        self::assertSame('600', $cardImage->attr('width'));
        self::assertSame('338', $cardImage->attr('height'));
        self::assertSame(0, $crawler->filter('.article-admin-media-item picture')->count());

        self::assertSame(0, $crawler->filter('.article-admin-cover-preview')->count());
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
                    'galleryImages' => [
                        new UploadedFile($invalidImagePath, 'article-invalid.gif', 'image/gif', null, true),
                    ],
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

    public function testArticleFormHasNoSeparateCoverUploadBlock(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $client->loginUser($this->createVerifiedAdmin());

        foreach (['/admin/articles/new', sprintf('/admin/articles/%d/edit', $article->getId())] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame(0, $crawler->filter('input[name="coverImage"], input[name="removeCover"], #article-cover-image')->count());
            self::assertSame(1, $crawler->filter('input[name="galleryImages[]"]')->count());
            self::assertStringNotContainsString('Importer / remplacer', $crawler->text());
        }
    }

    public function testSubmittingAfterRemovingPendingUploadCreatesNoMediaOrFile(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $mediaCount = $this->entityManager()->getRepository(MediaAsset::class)->count([]);
        $title = 'Article sans upload orphelin '.$this->uniqueToken('article');
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/articles/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
            'title' => $title,
            'content' => '<p>Le fichier a été retiré de la sélection avant enregistrement.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
            'newCoverImageIndex' => '0',
        ]);

        self::assertResponseRedirects('/admin/articles');
        self::assertSame($mediaCount, $this->entityManager()->getRepository(MediaAsset::class)->count([]));
        $article = $this->entityManager()->getRepository(Article::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Article::class, $article);
        self::assertNull($article->getFeaturedImage());
        self::assertCount(0, $article->getMediaLinks());
    }

    public function testDuplicateCreateSubmissionOnlyCreatesOneArticle(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $title = 'Article double soumission '.$this->uniqueToken('article');
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();
        $payload = [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
            'title' => $title,
            'content' => '<p>Contenu article soumis une seule fois.</p>',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ];

        $client->request('POST', '/admin/articles/new', $payload);
        self::assertResponseRedirects('/admin/articles');

        $client->request('POST', '/admin/articles/new', $payload);
        self::assertResponseRedirects('/admin/articles');

        self::assertCount(1, $this->entityManager()->getRepository(Article::class)->findBy(['title' => $title]));
    }

    public function testInvalidCreateSubmissionCanBeCorrectedWithSameSubmissionToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $title = 'Article correction soumission '.$this->uniqueToken('article');
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();
        $payload = [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
            'title' => $title,
            'content' => '',
            'status' => ContentStatus::Draft->value,
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ];

        $client->request('POST', '/admin/articles/new', $payload);
        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(Article::class)->findOneBy(['title' => $title]));

        $payload['content'] = '<p>Contenu corrigé après erreur de validation.</p>';
        $client->request('POST', '/admin/articles/new', $payload);
        self::assertResponseRedirects('/admin/articles');
        self::assertCount(1, $this->entityManager()->getRepository(Article::class)->findBy(['title' => $title]));
    }

    public function testArchiveArticleRequiresValidCsrfAndNeverDeletesArticle(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $article = $this->createArticle();

        $client->request('POST', sprintf('/admin/articles/%d/archive', $article->getId()), ['_token' => 'bad-token']);
        self::assertResponseRedirects('/');
        $article = $this->refresh($article);
        self::assertSame(ContentStatus::Published, $article->getStatus());

        $crawler = $client->request('GET', '/admin/articles');
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/articles/%d/archive', $article->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/articles/%d/archive', $article->getId())),
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->refresh($article);
        self::assertSame(ContentStatus::Archived, $article->getStatus());
        self::assertNotNull($this->entityManager()->find(Article::class, $article->getId()));
    }

    public function testPermanentDeleteRequiresPostAndValidCsrf(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $articleId = $article->getId();
        self::assertIsInt($articleId);
        $client->loginUser($this->createVerifiedAdmin());

        $client->request('GET', sprintf('/admin/articles/%d/delete', $articleId));
        self::assertResponseStatusCodeSame(405);

        $client->request('POST', sprintf('/admin/articles/%d/delete', $articleId), ['_token' => 'bad-token']);
        self::assertResponseRedirects('/');
        self::assertNotNull($this->entityManager()->find(Article::class, $articleId));
    }

    public function testPermanentDeleteRemovesArticleCommentsAndUnusedMediaFiles(): void
    {
        $client = static::createClient();
        $article = $this->createArticle();
        $articleId = $article->getId();
        $articleSlug = (string) $article->getSlug();
        self::assertIsInt($articleId);
        $media = $this->createStoredArticleImageMedia('Illustration propre à supprimer');
        $mediaId = $media->getId();
        self::assertIsInt($mediaId);
        $this->linkArticleMedia($article, $media, MediaRole::Cover, 0);
        $article->setFeaturedImage($media);
        $article->setContent(sprintf('<p>Contenu [[media:%d]] à supprimer.</p>', $mediaId));
        $author = $this->createUser();
        $comment = $this->createComment($author, $article);
        $commentId = $comment->getId();
        self::assertIsInt($commentId);
        $notificationLog = new PublicationNotificationLog('article', $articleId, 1);
        $this->persistAndFlush($article, $notificationLog);
        $notificationLogId = $notificationLog->getId();
        self::assertIsInt($notificationLogId);
        $files = $this->mediaFiles($media);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/articles');
        self::assertResponseIsSuccessful();
        $deletePath = sprintf('/admin/articles/%d/delete', $articleId);
        $client->request('POST', $deletePath, [
            '_token' => $this->tokenFromFormAction($crawler, $deletePath),
        ]);

        self::assertResponseRedirects('/admin/articles');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('a été supprimé définitivement', $crawler->text());
        self::assertSame(0, $crawler->filter(sprintf('a[href="/admin/articles/%d/edit"]', $articleId))->count());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Article::class, $articleId));
        self::assertNull($this->entityManager()->find(Comment::class, $commentId));
        self::assertNull($this->entityManager()->find(PublicationNotificationLog::class, $notificationLogId));
        self::assertNull($this->entityManager()->find(MediaAsset::class, $mediaId));
        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }

        $client->request('GET', sprintf('/articles/%s', $articleSlug));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPermanentDeletePreservesSharedMediaAndFiles(): void
    {
        $client = static::createClient();
        $article = $this->createDraftArticle();
        $otherArticle = $this->createDraftArticle('Article conservant le média '.$this->uniqueToken('article'));
        $articleId = $article->getId();
        self::assertIsInt($articleId);
        $media = $this->createStoredArticleImageMedia('Illustration partagée à conserver');
        $mediaId = $media->getId();
        self::assertIsInt($mediaId);
        $this->linkArticleMedia($article, $media, MediaRole::Gallery, 0);
        $otherLink = $this->linkArticleMedia($otherArticle, $media, MediaRole::Gallery, 0);
        $otherLinkId = $otherLink->getId();
        self::assertIsInt($otherLinkId);
        $files = $this->mediaFiles($media);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/articles');
        $deletePath = sprintf('/admin/articles/%d/delete', $articleId);
        $client->request('POST', $deletePath, [
            '_token' => $this->tokenFromFormAction($crawler, $deletePath),
        ]);

        self::assertResponseRedirects('/admin/articles');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Article::class, $articleId));
        self::assertNotNull($this->entityManager()->find(ArticleMedia::class, $otherLinkId));
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $mediaId));
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
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

    private function createStoredArticleImageMedia(string $title): MediaAsset
    {
        $token = $this->uniqueToken('article-media');
        $publicMediaDirectory = TestImageFactory::publicMediaDirectory();
        $variantDirectory = $publicMediaDirectory.'/variants';
        if (!is_dir($variantDirectory)) {
            mkdir($variantDirectory, 0775, true);
        }

        $sourcePath = sprintf('/uploads/media/%s.jpg', $token);
        $thumbPath = sprintf('/uploads/media/variants/%s-thumb.webp', $token);
        $mobilePath = sprintf('/uploads/media/variants/%s-mobile.webp', $token);
        $mediumPath = sprintf('/uploads/media/variants/%s-medium.webp', $token);
        $largePath = sprintf('/uploads/media/variants/%s-large.webp', $token);

        foreach ([$sourcePath, $thumbPath, $mobilePath, $mediumPath, $largePath] as $publicPath) {
            file_put_contents(TestImageFactory::projectDir().'/public'.$publicPath, 'image '.$token);
        }

        $media = (new MediaAsset())
            ->setTitle($title)
            ->setAltText('Texte alternatif '.$token)
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($sourcePath)
            ->setThumbnailPath($thumbPath)
            ->setMimeType('image/jpeg')
            ->setFileSize(128)
            ->setWidth(1920)
            ->setHeight(1080)
            ->setVariants([
                'thumb' => ['webp' => $thumbPath, 'width' => 600, 'height' => 338],
                'mobile' => ['webp' => $mobilePath, 'width' => 960, 'height' => 540],
                'medium' => ['webp' => $mediumPath, 'width' => 1600, 'height' => 900],
                'large' => ['webp' => $largePath, 'width' => 1920, 'height' => 1080],
            ]);

        $this->persistAndFlush($media);

        return $media;
    }

    /** @return list<string> */
    private function mediaFiles(MediaAsset $media): array
    {
        $paths = [];
        foreach ([$media->getFilePath(), $media->getThumbnailPath()] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        $variants = $media->getVariants();
        if (is_array($variants)) {
            array_walk_recursive($variants, static function (mixed $value) use (&$paths): void {
                if (is_string($value) && str_starts_with($value, '/uploads/media/')) {
                    $paths[] = $value;
                }
            });
        }

        $files = [];
        foreach (array_unique($paths) as $path) {
            $files[] = TestImageFactory::projectDir().'/public'.$path;
        }

        return $files;
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
