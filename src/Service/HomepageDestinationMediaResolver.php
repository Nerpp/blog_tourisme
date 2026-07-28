<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;
use App\Service\Media\ContentImageResolver;

final readonly class HomepageDestinationMediaResolver
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private HikeDraftRepository $hikeDraftRepository,
        private CityVisitDraftRepository $cityVisitDraftRepository,
        private PlaceRepository $placeRepository,
        private ContentImageResolver $contentImageResolver,
    ) {
    }

    public function representativeMedia(Destination $destination): ?MediaAsset
    {
        /** @var list<array{date: \DateTimeImmutable, media: MediaAsset}> $candidates */
        $candidates = [];

        $article = $this->articleRepository->findLatestPublishedWithMediaByDestination($destination);
        $articleMedia = $this->firstArticleMedia($article);
        if ($article instanceof Article && $article->getPublishedAt() instanceof \DateTimeImmutable && $articleMedia instanceof MediaAsset) {
            $candidates[] = ['date' => $article->getPublishedAt(), 'media' => $articleMedia];
        }

        $hike = $this->hikeDraftRepository->findLatestPublicWithMediaByDestination($destination);
        $hikeMedia = $this->firstHikeMedia($hike);
        if ($hike instanceof HikeDraft && $hike->getFinishedAt() instanceof \DateTimeImmutable && $hikeMedia instanceof MediaAsset) {
            $candidates[] = ['date' => $hike->getFinishedAt(), 'media' => $hikeMedia];
        }

        $cityVisit = $this->cityVisitDraftRepository->findLatestPublicWithMediaByDestination($destination);
        $cityVisitMedia = $this->firstCityVisitMedia($cityVisit);
        if ($cityVisit instanceof CityVisitDraft && $cityVisit->getFinishedAt() instanceof \DateTimeImmutable && $cityVisitMedia instanceof MediaAsset) {
            $candidates[] = ['date' => $cityVisit->getFinishedAt(), 'media' => $cityVisitMedia];
        }

        $place = $this->placeRepository->findLatestPublishedWithMediaByDestination($destination);
        $placeMedia = $this->firstPlaceMedia($place);
        if ($place instanceof Place && $place->getPublishedAt() instanceof \DateTimeImmutable && $placeMedia instanceof MediaAsset) {
            $candidates[] = ['date' => $place->getPublishedAt(), 'media' => $placeMedia];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $first, array $second): int => $second['date'] <=> $first['date'],
        );

        return $candidates[0]['media'];
    }

    private function firstArticleMedia(?Article $article): ?MediaAsset
    {
        if (!$article instanceof Article) {
            return null;
        }

        return $this->contentImageResolver->resolve($article, standardOnly: true);
    }

    private function firstHikeMedia(?HikeDraft $hike): ?MediaAsset
    {
        if (!$hike instanceof HikeDraft) {
            return null;
        }

        return $this->contentImageResolver->resolve($hike, standardOnly: true);
    }

    private function firstCityVisitMedia(?CityVisitDraft $cityVisit): ?MediaAsset
    {
        if (!$cityVisit instanceof CityVisitDraft) {
            return null;
        }

        return $this->contentImageResolver->resolve($cityVisit, standardOnly: true);
    }

    private function firstPlaceMedia(?Place $place): ?MediaAsset
    {
        if (!$place instanceof Place) {
            return null;
        }

        return $this->contentImageResolver->resolve($place, standardOnly: true);
    }
}
