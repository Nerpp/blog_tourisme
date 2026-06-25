<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\ImageType;
use App\Enum\MediaRole;
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

        return $this->mainImageFromLinks($article->getMediaLinks(), $article->getFeaturedImage());
    }

    private function firstHikeMedia(?HikeDraft $hike): ?MediaAsset
    {
        if (!$hike instanceof HikeDraft) {
            return null;
        }

        return $this->mainImageFromLinks($hike->getMediaLinks());
    }

    private function firstCityVisitMedia(?CityVisitDraft $cityVisit): ?MediaAsset
    {
        if (!$cityVisit instanceof CityVisitDraft) {
            return null;
        }

        return $this->mainImageFromLinks($cityVisit->getMediaLinks());
    }

    private function firstPlaceMedia(?Place $place): ?MediaAsset
    {
        if (!$place instanceof Place) {
            return null;
        }

        return $this->mainImageFromLinks($place->getMediaLinks(), $place->getFeaturedImage());
    }

    /** @param iterable<object> $mediaLinks */
    private function mainImageFromLinks(iterable $mediaLinks, ?MediaAsset $featuredImage = null): ?MediaAsset
    {
        $fallback = $featuredImage instanceof MediaAsset && $this->isStandardImage($featuredImage)
            ? $featuredImage
            : null;

        foreach ($mediaLinks as $mediaLink) {
            if (!method_exists($mediaLink, 'getMediaAsset') || !method_exists($mediaLink, 'getRole')) {
                continue;
            }

            $media = $mediaLink->getMediaAsset();
            if (!$media instanceof MediaAsset || !$this->isStandardImage($media)) {
                continue;
            }

            if ($mediaLink->getRole() === MediaRole::Cover) {
                return $media;
            }

            $fallback ??= $media;
        }

        return $fallback;
    }

    private function isStandardImage(MediaAsset $media): bool
    {
        return $media->getMediaType() === MediaType::Image
            && $media->getImageType() === ImageType::Standard;
    }
}
