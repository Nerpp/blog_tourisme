<?php

namespace App\Service;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Enum\MediaType;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;

final readonly class HomepageDestinationMediaResolver
{
    public function __construct(
        private HikeDraftRepository $hikeDraftRepository,
        private CityVisitDraftRepository $cityVisitDraftRepository,
        private PlaceRepository $placeRepository,
    ) {
    }

    public function representativeMedia(Destination $destination): ?MediaAsset
    {
        return $this->firstHikeMedia($this->hikeDraftRepository->findLatestPublicWithMediaByDestination($destination))
            ?? $this->firstCityVisitMedia($this->cityVisitDraftRepository->findLatestPublicWithMediaByDestination($destination))
            ?? $this->firstPlaceMedia($this->placeRepository->findLatestPublishedWithMediaByDestination($destination));
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
