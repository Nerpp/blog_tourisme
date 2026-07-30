<?php

namespace App\Tests\Unit\Article;

use App\Entity\Article;
use App\Service\Article\ArticleSeoMetadataProvider;
use App\Service\Seo\PublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class ArticleSeoMetadataProviderTest extends TestCase
{
    public function testItBuildsMetadataFromEditorialSourcesAndThePublicRoute(): void
    {
        $article = (new Article())
            ->setTitle('Titre public de l’article')
            ->setSlug('titre-public-article')
            ->setExcerpt("<p>  Un&nbsp; résumé</p><p>avec <strong>du HTML</strong>. </p><script>à ignorer</script>")
            ->setSeoTitle('Ancien titre SEO à ignorer')
            ->setSeoDescription('Ancienne description SEO à ignorer')
            ->setCanonicalUrl('https://legacy.example/article');
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_article_show',
                ['slug' => 'titre-public-article'],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/articles/titre-public-article');

        $metadata = (new ArticleSeoMetadataProvider(new PublicUrlGenerator($router, 'https://example.test')))->provide($article);

        self::assertSame('Titre public de l’article', $metadata['title']);
        self::assertSame('Un résumé avec du HTML.', $metadata['description']);
        self::assertSame('https://example.test/articles/titre-public-article', $metadata['canonical']);
    }

    public function testItTruncatesTheDescriptionOnAWordBoundary(): void
    {
        $article = (new Article())
            ->setTitle('Article long')
            ->setSlug('article-long')
            ->setExcerpt(str_repeat('description éditoriale complète ', 12));
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/articles/article-long');

        $description = (new ArticleSeoMetadataProvider(new PublicUrlGenerator($router, 'https://example.test')))->provide($article)['description'];

        self::assertLessThanOrEqual(160, mb_strlen($description));
        self::assertStringEndsWith('…', $description);
        self::assertDoesNotMatchRegularExpression('/\s…$/u', $description);
    }
}
