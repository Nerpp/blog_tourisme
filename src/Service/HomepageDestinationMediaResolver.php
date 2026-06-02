<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\MediaType;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;

final readonly class HomepageDestinationMediaResolver
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private HikeDraftRepository $hikeDraftRepository,
        private CityVisitDraftRepository $cityVisitDraftRepository,
        private PlaceRepository $placeRepository,
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

        if ($article->getFeaturedImage()?->getMediaType() === MediaType::Image) {
            return $article->getFeaturedImage();
        }

        foreach ($article->getMediaLinks() as $mediaLink) {
            $media = $mediaLink->getMediaAsset();
            if ($media->getMediaType() === MediaType::Image) {
                return $media;
            }
        }

        return null;
    }

    private function firstHikeMedia(?HikeDraft $hike): ?MediaAsset
    {
        if (!$hike instanceof HikeDraft) {
            return null;
        }

        foreach ($hike->getMediaLinks() as $mediaLink) {
            $media = $mediaLink->getMediaAsset();
            if ($media->getMediaType() === MediaType::Image) {
                return $media;
            }
        }

        return null;
    }

    private function firstCityVisitMedia(?CityVisitDraft $cityVisit): ?MediaAsset
    {
        if (!$cityVisit instanceof CityVisitDraft) {
            return null;
        }

        foreach ($cityVisit->getMediaLinks() as $mediaLink) {
            $media = $mediaLink->getMediaAsset();
            if ($media->getMediaType() === MediaType::Image) {
                return $media;
            }
        }

        return null;
    }

    private function firstPlaceMedia(?Place $place): ?MediaAsset
    {
        if (!$place instanceof Place) {
            return null;
        }

        if ($place->getFeaturedImage()?->getMediaType() === MediaType::Image) {
            return $place->getFeaturedImage();
        }

        foreach ($place->getMediaLinks() as $mediaLink) {
            $media = $mediaLink->getMediaAsset();
            if ($media->getMediaType() === MediaType::Image) {
                return $media;
            }
        }

        return null;
    }
}
