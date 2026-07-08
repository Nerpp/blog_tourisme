<?php

namespace App\Tests\Integration;

use App\Entity\Article;
use App\Enum\ContentStatus;
use App\Repository\ArticleRepository;
use DateTimeImmutable;

final class SlugOrSeoIntegrationTest extends IntegrationTestCase
{
    public function testArticleSlugRemainsStableWhenTitleChanges(): void
    {
        $token = bin2hex(random_bytes(4));
        $author = $this->createUser();
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle('Original SEO title '.$token)
            ->setSlug('original-seo-slug-'.$token)
            ->setContent('Stable slug integration test content.')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));

        $this->entityManager->persist($author);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setTitle('Updated SEO title '.$token);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = $this->service(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        $stored = $repository->findOneBy(['slug' => 'original-seo-slug-'.$token]);
        self::assertInstanceOf(Article::class, $stored);
        self::assertSame('Updated SEO title '.$token, $stored->getTitle());
        self::assertSame('original-seo-slug-'.$token, $stored->getSlug());
    }
}
