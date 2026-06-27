<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\Category;
use App\Enum\CategoryType;

final class AdminArticleCategoryControllerTest extends FunctionalTestCase
{
    public function testVerifiedAdminCanCreateAndEditArticleCategory(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $token = $this->uniqueToken('article-category');

        $crawler = $client->request('GET', '/admin/article-categories/new');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/admin/article-categories/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => 'Catégorie '.$token,
            'slug' => $token,
            'description' => 'Description initiale.',
        ]);

        self::assertResponseRedirects('/admin/article-categories');
        $category = $this->entityManager()->getRepository(Category::class)->findOneBy(['slug' => $token]);
        self::assertInstanceOf(Category::class, $category);
        self::assertSame(CategoryType::Article, $category->getType());

        $crawler = $client->request('GET', sprintf('/admin/article-categories/%d/edit', $category->getId()));
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/article-categories/%d/edit', $category->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'name' => 'Catégorie modifiée '.$token,
            'slug' => $token.'-modifiee',
            'description' => 'Description modifiée.',
        ]);

        self::assertResponseRedirects('/admin/article-categories');
        $category = $this->refresh($category);
        self::assertSame('Catégorie modifiée '.$token, $category->getName());
        self::assertSame($token.'-modifiee', $category->getSlug());
        self::assertSame('Description modifiée.', $category->getDescription());
        self::assertSame(CategoryType::Article, $category->getType());
    }

    public function testUnusedArticleCategoryCanBeDeleted(): void
    {
        $client = static::createClient();
        $category = $this->createCategory(CategoryType::Article);
        $categoryId = $category->getId();
        self::assertIsInt($categoryId);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/article-categories');
        self::assertResponseIsSuccessful();
        $path = sprintf('/admin/article-categories/%d/delete', $categoryId);
        $client->request('POST', $path, [
            '_token' => $this->tokenFromFormAction($crawler, $path),
        ]);

        self::assertResponseRedirects('/admin/article-categories');
        self::assertNull($this->entityManager()->find(Category::class, $categoryId));
    }

    public function testUsedArticleCategoryCannotBeDeleted(): void
    {
        $client = static::createClient();
        $category = $this->createCategory(CategoryType::Article);
        $article = $this->createArticle();
        $article->setCategory($category);
        $this->persistAndFlush($article);
        $categoryId = $category->getId();
        self::assertIsInt($categoryId);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/article-categories');
        self::assertResponseIsSuccessful();
        $path = sprintf('/admin/article-categories/%d/delete', $categoryId);
        $client->request('POST', $path, [
            '_token' => $this->tokenFromFormAction($crawler, $path),
        ]);

        self::assertResponseRedirects('/admin/article-categories');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Cette catégorie ne peut pas être supprimée car elle est encore utilisée par 1 article(s).');
        self::assertNotNull($this->entityManager()->find(Category::class, $categoryId));
        self::assertSame($categoryId, $this->entityManager()->find(Article::class, $article->getId())?->getCategory()?->getId());
    }

    public function testSharedAndPlaceCategoriesAreNotChangedByArticleCategoryCrud(): void
    {
        $client = static::createClient();
        $sharedCategory = $this->createCategory(CategoryType::Both);
        $placeCategory = $this->createCategory(CategoryType::Place);
        $sharedCategoryId = $sharedCategory->getId();
        $placeCategoryId = $placeCategory->getId();
        self::assertIsInt($sharedCategoryId);
        self::assertIsInt($placeCategoryId);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/article-categories');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $sharedCategory->getName(), $crawler->text());
        self::assertStringNotContainsString((string) $placeCategory->getName(), $crawler->text());

        $sharedCategory = $this->entityManager()->find(Category::class, $sharedCategoryId);
        self::assertInstanceOf(Category::class, $sharedCategory);
        $sharedCategory->setType(CategoryType::Article);
        $this->entityManager()->flush();
        $deletePath = sprintf('/admin/article-categories/%d/delete', $sharedCategoryId);
        $crawler = $client->request('GET', '/admin/article-categories');
        $deleteToken = $this->tokenFromFormAction($crawler, $deletePath);
        $sharedCategory = $this->entityManager()->find(Category::class, $sharedCategoryId);
        self::assertInstanceOf(Category::class, $sharedCategory);
        $sharedCategory->setType(CategoryType::Both);
        $this->entityManager()->flush();

        $client->request('POST', $deletePath, ['_token' => $deleteToken]);

        self::assertResponseRedirects('/admin/article-categories');
        self::assertNotNull($this->entityManager()->find(Category::class, $sharedCategoryId));
        self::assertNotNull($this->entityManager()->find(Category::class, $placeCategoryId));
    }

    public function testArticleCategoryUsedByPlaceCannotBeDeleted(): void
    {
        $client = static::createClient();
        $category = $this->createCategory(CategoryType::Article);
        $place = $this->createPlace(null, $category);
        $categoryId = $category->getId();
        self::assertIsInt($categoryId);
        $client->loginUser($this->createVerifiedAdmin());

        $crawler = $client->request('GET', '/admin/article-categories');
        self::assertResponseIsSuccessful();
        $deletePath = sprintf('/admin/article-categories/%d/delete', $categoryId);
        $client->request('POST', $deletePath, [
            '_token' => $this->tokenFromFormAction($crawler, $deletePath),
        ]);

        self::assertResponseRedirects('/admin/article-categories');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Cette catégorie ne peut pas être supprimée car elle est encore utilisée par 1 lieu(x).');
        self::assertNotNull($this->entityManager()->find(Category::class, $categoryId));
        self::assertSame($categoryId, $this->entityManager()->find($place::class, $place->getId())?->getCategory()?->getId());
    }
}
