<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Entity\ArticleDestination;
use App\Entity\Category;
use App\Entity\Destination;
use App\Enum\CategoryType;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Repository\ArticleRepository;

final class ArticleRepositoryIntegrationTest extends IntegrationTestCase
{
    public function testCountPublicArticlesUsesListingVisibilityAndCountsEachArticleOnce(): void
    {
        $token = $this->uniqueToken('public-count');
        $repository = $this->repository();

        self::assertSame(0, $repository->countPublicArticles($token));

        $firstCategory = $this->category('Première catégorie '.$token);
        $secondCategory = $this->category('Seconde catégorie '.$token);
        $firstDestination = $this->destination('Première destination '.$token);
        $secondDestination = $this->destination('Seconde destination '.$token);
        $publicArticles = [];

        for ($index = 1; $index <= 5; ++$index) {
            $category = match ($index) {
                1, 2 => $firstCategory,
                3, 4 => $secondCategory,
                default => null,
            };
            $publicArticles[] = $this->article($token, $index, ContentStatus::Published, $category);
        }

        $this->entityManager->persist(
            (new ArticleDestination())
                ->setArticle($publicArticles[0])
                ->setDestination($firstDestination),
        );
        $this->entityManager->persist(
            (new ArticleDestination())
                ->setArticle($publicArticles[0])
                ->setDestination($secondDestination),
        );
        $this->article($token, 6, ContentStatus::Draft);
        $this->article($token, 7, ContentStatus::Archived);
        $this->article($token, 8, ContentStatus::PrivateContent);
        $this->entityManager->flush();

        $listedArticles = $repository->findPublishedForListing($token);
        $listedIds = array_map(static fn (Article $article): ?int => $article->getId(), $listedArticles);

        self::assertSame(5, $repository->countPublicArticles($token));
        self::assertCount(5, $listedArticles);
        self::assertCount(5, array_unique($listedIds));
        self::assertContains($publicArticles[4]->getId(), $listedIds, 'Un article public sans relation facultative doit être compté.');
    }

    public function testCountPublicArticlesIsIndependentFromListingLimit(): void
    {
        $token = $this->uniqueToken('public-pagination');

        for ($index = 1; $index <= 7; ++$index) {
            $this->article($token, $index);
        }
        $this->entityManager->flush();

        self::assertCount(6, $this->repository()->findPublishedForListing($token, 6));
        self::assertSame(7, $this->repository()->countPublicArticles($token));
    }

    public function testCountPublicArticlesSharesSearchAndCategoryFiltersWithListing(): void
    {
        $token = $this->uniqueToken('public-filter');
        $firstCategory = $this->category('Catégorie filtrée '.$token);
        $secondCategory = $this->category('Catégorie exclue '.$token);
        $firstMatch = $this->article($token, 1, ContentStatus::Published, $firstCategory);
        $secondMatch = $this->article($token, 2, ContentStatus::Published, $firstCategory);
        $this->article($token, 3, ContentStatus::Published, $secondCategory);
        $this->entityManager->flush();

        $listed = $this->repository()->findPublishedForListing($token, categorySlug: $firstCategory->getSlug());
        $listedIds = array_map(static fn (Article $article): ?int => $article->getId(), $listed);

        self::assertSame(2, $this->repository()->countPublicArticles($token, $firstCategory->getSlug()));
        self::assertEqualsCanonicalizing([$firstMatch->getId(), $secondMatch->getId()], $listedIds);
    }

    private function article(
        string $token,
        int $index,
        ContentStatus $status = ContentStatus::Published,
        ?Category $category = null,
    ): Article {
        $article = (new Article())
            ->setTitle(sprintf('Article public %s %d', $token, $index))
            ->setSlug(sprintf('%s-%d', $token, $index))
            ->setExcerpt('Extrait '.$token)
            ->setContent('<p>Contenu de test.</p>')
            ->setStatus($status)
            ->setCategory($category)
            ->setPublishedAt($status === ContentStatus::Published ? new \DateTimeImmutable('-1 hour') : null);

        $this->entityManager->persist($article);

        return $article;
    }

    private function category(string $name): Category
    {
        $category = (new Category())
            ->setName($name)
            ->setSlug(strtolower(str_replace([' ', '_'], '-', $name)))
            ->setType(CategoryType::Article);

        $this->entityManager->persist($category);

        return $category;
    }

    private function destination(string $name): Destination
    {
        $destination = (new Destination())
            ->setName($name)
            ->setSlug(strtolower(str_replace([' ', '_'], '-', $name)))
            ->setType(DestinationType::Area);

        $this->entityManager->persist($destination);

        return $destination;
    }

    private function repository(): ArticleRepository
    {
        $repository = $this->service(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        return $repository;
    }
}
