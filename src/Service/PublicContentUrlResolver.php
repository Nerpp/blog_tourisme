<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class PublicContentUrlResolver
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function articleUrl(Article $article): ?string
    {
        if (!$article->getSlug()) {
            return null;
        }

        return $this->urlGenerator->generate('app_article_show', [
            'slug' => $article->getSlug(),
        ]);
    }

    public function hikeUrl(HikeDraft $hike): ?string
    {
        if (!$hike->getSlug()) {
            return null;
        }

        return $this->urlGenerator->generate('app_hike_show', [
            'slug' => $hike->getSlug(),
        ]);
    }

    public function cityVisitUrl(CityVisitDraft $cityVisit): ?string
    {
        if (!$cityVisit->getSlug()) {
            return null;
        }

        return $this->urlGenerator->generate('app_city_visit_show', [
            'slug' => $cityVisit->getSlug(),
        ]);
    }
}