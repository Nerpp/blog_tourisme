<?php

namespace App\Service\Traffic;

use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use Symfony\Component\HttpFoundation\Request;

final class TrafficContentResolver
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly HikeDraftRepository $hikeDraftRepository,
        private readonly CityVisitDraftRepository $cityVisitDraftRepository,
        private readonly DestinationRepository $destinationRepository,
        private readonly PlaceRepository $placeRepository,
    ) {
    }

    /**
     * @return array{contentType: string, contentId: int|null, contentTitle: string|null}
     */
    public function resolve(Request $request): array
    {
        $routeValue = $request->attributes->get('_route');
        $route = is_string($routeValue) ? $routeValue : '';
        $slug = $request->attributes->get('slug');
        $slug = is_string($slug) ? $slug : null;

        return match ($route) {
            'app_home' => ['contentType' => 'home', 'contentId' => null, 'contentTitle' => 'Accueil'],
            'app_article_index' => ['contentType' => 'article_index', 'contentId' => null, 'contentTitle' => 'Articles'],
            'app_destination_index' => ['contentType' => 'destination_index', 'contentId' => null, 'contentTitle' => 'Destinations'],
            'app_place_index' => ['contentType' => 'place_index', 'contentId' => null, 'contentTitle' => 'Lieux'],
            'app_article_show' => $this->resolveArticle($slug),
            'app_hike_show' => $this->resolveHike($slug),
            'app_city_visit_show' => $this->resolveCityVisit($slug),
            'app_destination_show' => $this->resolveDestination($slug),
            'app_place_show' => $this->resolvePlace($slug),
            default => ['contentType' => $routeValue === null ? 'error' : 'other', 'contentId' => null, 'contentTitle' => null],
        };
    }

    /** @return array{contentType: string, contentId: int|null, contentTitle: string|null} */
    private function resolveArticle(?string $slug): array
    {
        $article = $slug !== null ? $this->articleRepository->findOneBy(['slug' => $slug]) : null;

        return ['contentType' => 'article', 'contentId' => $article?->getId(), 'contentTitle' => $article?->getTitle()];
    }

    /** @return array{contentType: string, contentId: int|null, contentTitle: string|null} */
    private function resolveHike(?string $slug): array
    {
        $hike = $slug !== null ? $this->hikeDraftRepository->findOneBy(['slug' => $slug]) : null;

        return ['contentType' => 'hike', 'contentId' => $hike?->getId(), 'contentTitle' => $hike?->getTitle()];
    }

    /** @return array{contentType: string, contentId: int|null, contentTitle: string|null} */
    private function resolveCityVisit(?string $slug): array
    {
        $cityVisit = $slug !== null ? $this->cityVisitDraftRepository->findOneBy(['slug' => $slug]) : null;

        return ['contentType' => 'city_visit', 'contentId' => $cityVisit?->getId(), 'contentTitle' => $cityVisit?->getTitle()];
    }

    /** @return array{contentType: string, contentId: int|null, contentTitle: string|null} */
    private function resolveDestination(?string $slug): array
    {
        $destination = $slug !== null ? $this->destinationRepository->findOneBy(['slug' => $slug]) : null;

        return ['contentType' => 'destination', 'contentId' => $destination?->getId(), 'contentTitle' => $destination?->getName()];
    }

    /** @return array{contentType: string, contentId: int|null, contentTitle: string|null} */
    private function resolvePlace(?string $slug): array
    {
        $place = $slug !== null ? $this->placeRepository->findOneBy(['slug' => $slug]) : null;

        return ['contentType' => 'place', 'contentId' => $place?->getId(), 'contentTitle' => $place?->getName()];
    }
}
