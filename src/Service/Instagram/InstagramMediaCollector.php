<?php

namespace App\Service\Instagram;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPoint;
use App\Entity\CityVisitPointMedia;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePoint;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;

final readonly class InstagramMediaCollector
{
    public function __construct(private MediaPublicUrlResolver $publicUrlResolver)
    {
    }

    /** @return list<InstagramMedia> */
    public function collect(HikeDraft|CityVisitDraft $content): array
    {
        $candidates = $content instanceof HikeDraft
            ? $this->hikeCandidates($content)
            : $this->cityVisitCandidates($content);
        $result = [];
        $seenIds = [];
        $seenUrls = [];

        foreach ($candidates as $mediaAsset) {
            if (!$this->isCompatiblePhoto($mediaAsset)) {
                continue;
            }

            $mediaId = $mediaAsset->getId();
            if ($mediaId !== null && isset($seenIds[$mediaId])) {
                continue;
            }

            $url = $this->publicUrlResolver->resolve($mediaAsset);
            if ($url === null || isset($seenUrls[$url])) {
                continue;
            }

            if ($mediaId !== null) {
                $seenIds[$mediaId] = true;
            }
            $seenUrls[$url] = true;
            $result[] = new InstagramMedia($mediaAsset, $url);
        }

        return $result;
    }

    /** @return list<MediaAsset> */
    private function hikeCandidates(HikeDraft $hike): array
    {
        /** @var list<HikeDraftMedia> $directLinks */
        $directLinks = array_values($hike->getMediaLinks()->toArray());
        usort($directLinks, $this->compareHikeDirectLinks(...));

        /** @var list<HikePoint> $points */
        $points = array_values($hike->getPoints()->toArray());
        usort($points, $this->compareHikePoints(...));

        $candidates = [];
        foreach ($directLinks as $link) {
            $mediaAsset = $link->getMediaAsset();
            if ($mediaAsset instanceof MediaAsset) {
                $candidates[] = $mediaAsset;
            }
        }

        foreach ($points as $point) {
            /** @var list<HikePointMedia> $pointLinks */
            $pointLinks = array_values($point->getMediaLinks()->toArray());
            usort($pointLinks, $this->compareHikePointLinks(...));

            foreach ($pointLinks as $link) {
                $mediaAsset = $link->getMediaAsset();
                if ($mediaAsset instanceof MediaAsset) {
                    $candidates[] = $mediaAsset;
                }
            }
        }

        return $candidates;
    }

    /** @return list<MediaAsset> */
    private function cityVisitCandidates(CityVisitDraft $cityVisit): array
    {
        /** @var list<CityVisitDraftMedia> $directLinks */
        $directLinks = array_values($cityVisit->getMediaLinks()->toArray());
        usort($directLinks, $this->compareCityVisitDirectLinks(...));

        /** @var list<CityVisitPoint> $points */
        $points = array_values($cityVisit->getPoints()->toArray());
        usort($points, $this->compareCityVisitPoints(...));

        $candidates = [];
        foreach ($directLinks as $link) {
            $mediaAsset = $link->getMediaAsset();
            if ($mediaAsset instanceof MediaAsset) {
                $candidates[] = $mediaAsset;
            }
        }

        foreach ($points as $point) {
            /** @var list<CityVisitPointMedia> $pointLinks */
            $pointLinks = array_values($point->getMediaLinks()->toArray());
            usort($pointLinks, $this->compareCityVisitPointLinks(...));

            foreach ($pointLinks as $link) {
                $mediaAsset = $link->getMediaAsset();
                if ($mediaAsset instanceof MediaAsset) {
                    $candidates[] = $mediaAsset;
                }
            }
        }

        return $candidates;
    }

    private function compareHikeDirectLinks(HikeDraftMedia $left, HikeDraftMedia $right): int
    {
        return $this->compareDirectLinkValues(
            $left->getRole(),
            $left->getPosition(),
            $left->getId(),
            $right->getRole(),
            $right->getPosition(),
            $right->getId(),
        );
    }

    private function compareCityVisitDirectLinks(CityVisitDraftMedia $left, CityVisitDraftMedia $right): int
    {
        return $this->compareDirectLinkValues(
            $left->getRole(),
            $left->getPosition(),
            $left->getId(),
            $right->getRole(),
            $right->getPosition(),
            $right->getId(),
        );
    }

    private function compareDirectLinkValues(
        MediaRole $leftRole,
        int $leftPosition,
        ?int $leftId,
        MediaRole $rightRole,
        int $rightPosition,
        ?int $rightId,
    ): int {
        $roleComparison = (int) ($leftRole !== MediaRole::Cover) <=> (int) ($rightRole !== MediaRole::Cover);
        if ($roleComparison !== 0) {
            return $roleComparison;
        }

        return $leftPosition <=> $rightPosition ?: $this->compareNullableIds($leftId, $rightId);
    }

    private function compareHikePoints(HikePoint $left, HikePoint $right): int
    {
        return $left->getPosition() <=> $right->getPosition()
            ?: $this->compareNullableIds($left->getId(), $right->getId());
    }

    private function compareCityVisitPoints(CityVisitPoint $left, CityVisitPoint $right): int
    {
        return $left->getPosition() <=> $right->getPosition()
            ?: $this->compareNullableIds($left->getId(), $right->getId());
    }

    private function compareHikePointLinks(HikePointMedia $left, HikePointMedia $right): int
    {
        return $this->comparePointLinkValues($left->getCreatedAt(), $left->getId(), $right->getCreatedAt(), $right->getId());
    }

    private function compareCityVisitPointLinks(CityVisitPointMedia $left, CityVisitPointMedia $right): int
    {
        return $this->comparePointLinkValues($left->getCreatedAt(), $left->getId(), $right->getCreatedAt(), $right->getId());
    }

    private function comparePointLinkValues(
        ?\DateTimeImmutable $leftCreatedAt,
        ?int $leftId,
        ?\DateTimeImmutable $rightCreatedAt,
        ?int $rightId,
    ): int {
        if ($leftCreatedAt !== $rightCreatedAt) {
            if ($leftCreatedAt === null) {
                return 1;
            }
            if ($rightCreatedAt === null) {
                return -1;
            }

            $createdAtComparison = $leftCreatedAt <=> $rightCreatedAt;
            if ($createdAtComparison !== 0) {
                return $createdAtComparison;
            }
        }

        return $this->compareNullableIds($leftId, $rightId);
    }

    private function compareNullableIds(?int $left, ?int $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        return $left <=> $right;
    }

    private function isCompatiblePhoto(MediaAsset $mediaAsset): bool
    {
        return $mediaAsset->getMediaType() === MediaType::Image
            && in_array($mediaAsset->getImageType(), [null, ImageType::Standard, ImageType::WideAngle], true);
    }
}
