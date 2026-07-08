<?php

namespace App\Tests\Unit;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Service\PublicContentUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class PublicContentUrlResolverTest extends TestCase
{
    public function testReturnsGeneratedUrlsForSluggedPublicContent(): void
    {
        $resolver = new PublicContentUrlResolver($this->urlGenerator());

        self::assertSame('/articles/escapade', $resolver->articleUrl((new Article())->setSlug('escapade')));
        self::assertSame('/randonnees/boucle-test', $resolver->hikeUrl((new HikeDraft())->setSlug('boucle-test')));
        self::assertSame('/visites/centre-ville', $resolver->cityVisitUrl((new CityVisitDraft())->setSlug('centre-ville')));
    }

    public function testReturnsNullWhenContentHasNoSlug(): void
    {
        $resolver = new PublicContentUrlResolver($this->urlGenerator());

        self::assertNull($resolver->articleUrl(new Article()));
        self::assertNull($resolver->hikeUrl(new HikeDraft()));
        self::assertNull($resolver->cityVisitUrl(new CityVisitDraft()));
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $slug = (string) ($parameters['slug'] ?? '');

                return match ($name) {
                    'app_article_show' => '/articles/'.$slug,
                    'app_hike_show' => '/randonnees/'.$slug,
                    'app_city_visit_show' => '/visites/'.$slug,
                    default => '/unknown/'.$slug,
                };
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
