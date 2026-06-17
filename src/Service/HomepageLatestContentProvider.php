<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\HikeDraftStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;

/**
 * @phpstan-type LatestContentItem array{
 *     type: string,
 *     label: string,
 *     title: string,
 *     url: string,
 *     image: string|null,
 *     date: \DateTimeImmutable,
 *     primary_cta: string,
 *     secondary_title?: string,
 *     secondary_url?: string,
 *     secondary_cta?: string
 * }
 */
final readonly class HomepageLatestContentProvider
{
    private const IMAGE_PLACEHOLDER = '/images/placeholders/destination-card-placeholder.webp';

    public function __construct(
        private ArticleRepository $articleRepository,
        private HikeDraftRepository $hikeDraftRepository,
        private CityVisitDraftRepository $cityVisitDraftRepository,
        private PublicContentUrlResolver $urlResolver,
    ) {}

    /** @return LatestContentItem|null */
    public function getLatest(): ?array
    {
        $items = array_filter([
            $this->normalizeArticle($this->articleRepository->findLatestPublishedForHomepage()),
            $this->normalizeHike($this->hikeDraftRepository->findLatestFinishedForHomepage()),
            $this->normalizeCityVisit($this->cityVisitDraftRepository->findLatestFinishedForHomepage()),
        ]);

        if ($items === []) {
            return null;
        }

        usort(
            $items,
            static fn(array $a, array $b): int => $b['date'] <=> $a['date']
        );

        return $items[0];
    }

    /** @return LatestContentItem|null */
    private function normalizeArticle(?Article $article): ?array
    {
        if ($article === null || $article->getPublishedAt() === null) {
            return null;
        }

        $url = $this->urlResolver->articleUrl($article);

        if ($url === null) {
            return null;
        }

        $linkedHike = $this->publicLinkedHike($article);
        if ($linkedHike instanceof HikeDraft) {
            $hikeUrl = $this->urlResolver->hikeUrl($linkedHike);
            if ($hikeUrl !== null) {
                return [
                    'type' => 'hike',
                    'label' => 'Nouvel article',
                    'title' => $linkedHike->getTitle() ?? 'Randonnée',
                    'url' => $hikeUrl,
                    'image' => $this->resolveMediaPath($this->mainImageFromLinks($linkedHike->getMediaLinks())),
                    'date' => $article->getPublishedAt(),
                    'primary_cta' => 'Découvrir la balade',
                    'secondary_title' => $article->getTitle() ?? 'Article associé',
                    'secondary_url' => $url,
                    'secondary_cta' => 'Lire l’article',
                ];
            }
        }

        $linkedCityVisit = $this->publicLinkedCityVisit($article);
        if ($linkedCityVisit instanceof CityVisitDraft) {
            $cityVisitUrl = $this->urlResolver->cityVisitUrl($linkedCityVisit);
            if ($cityVisitUrl !== null) {
                return [
                    'type' => 'city_visit',
                    'label' => 'Nouvel article',
                    'title' => $linkedCityVisit->getTitle() ?? 'Visite de ville',
                    'url' => $cityVisitUrl,
                    'image' => $this->resolveMediaPath($this->mainImageFromLinks($linkedCityVisit->getMediaLinks())),
                    'date' => $article->getPublishedAt(),
                    'primary_cta' => 'Découvrir la visite',
                    'secondary_title' => $article->getTitle() ?? 'Article associé',
                    'secondary_url' => $url,
                    'secondary_cta' => 'Lire l’article',
                ];
            }
        }

        $media = $this->mainImageFromLinks($article->getMediaLinks(), $article->getFeaturedImage());

        return [
            'type' => 'article',
            'label' => 'Dernier article',
            'title' => $article->getTitle() ?? 'Article',
            'url' => $url,
            'image' => $this->resolveMediaPath($media),
            'date' => $article->getPublishedAt(),
            'primary_cta' => 'Lire l’article',
        ];
    }

    /** @return LatestContentItem|null */
    private function normalizeHike(?HikeDraft $hike): ?array
    {
        if ($hike === null || $hike->getFinishedAt() === null) {
            return null;
        }

        $url = $this->urlResolver->hikeUrl($hike);

        if ($url === null) {
            return null;
        }

        return [
            'type' => 'hike',
            'label' => 'Dernière randonnée',
            'title' => $hike->getTitle() ?? 'Randonnée',
            'url' => $url,
            'image' => $this->resolveMediaPath($this->mainImageFromLinks($hike->getMediaLinks())),
            'date' => $hike->getFinishedAt(),
            'primary_cta' => 'Découvrir la balade',
        ];
    }

    /** @return LatestContentItem|null */
    private function normalizeCityVisit(?CityVisitDraft $cityVisit): ?array
    {
        if ($cityVisit === null || $cityVisit->getFinishedAt() === null) {
            return null;
        }

        $url = $this->urlResolver->cityVisitUrl($cityVisit);

        if ($url === null) {
            return null;
        }

        return [
            'type' => 'city_visit',
            'label' => 'Dernière visite de ville',
            'title' => $cityVisit->getTitle() ?? 'Visite de ville',
            'url' => $url,
            'image' => $this->resolveMediaPath($this->mainImageFromLinks($cityVisit->getMediaLinks())),
            'date' => $cityVisit->getFinishedAt(),
            'primary_cta' => 'Découvrir la visite',
        ];
    }

    private function publicLinkedHike(Article $article): ?HikeDraft
    {
        foreach ($article->getHikeLinks() as $link) {
            $hike = $link->getHikeDraft();
            if (
                $hike instanceof HikeDraft
                && in_array($hike->getStatus(), [HikeDraftStatus::Finished, HikeDraftStatus::Converted], true)
            ) {
                return $hike;
            }
        }

        return null;
    }

    private function publicLinkedCityVisit(Article $article): ?CityVisitDraft
    {
        foreach ($article->getCityVisitLinks() as $link) {
            $cityVisit = $link->getCityVisitDraft();
            if (
                $cityVisit instanceof CityVisitDraft
                && in_array($cityVisit->getStatus(), [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted], true)
            ) {
                return $cityVisit;
            }
        }

        return null;
    }

    /** @param iterable<object> $mediaLinks */
    private function mainImageFromLinks(iterable $mediaLinks, ?MediaAsset $featuredImage = null): ?MediaAsset
    {
        $fallback = $featuredImage?->getMediaType() === MediaType::Image ? $featuredImage : null;

        foreach ($mediaLinks as $mediaLink) {
            if (!method_exists($mediaLink, 'getMediaAsset') || !method_exists($mediaLink, 'getRole')) {
                continue;
            }

            $media = $mediaLink->getMediaAsset();
            if (!$media instanceof MediaAsset || $media->getMediaType() !== MediaType::Image) {
                continue;
            }

            if ($mediaLink->getRole() === MediaRole::Cover) {
                return $media;
            }

            $fallback ??= $media;
        }

        return $fallback;
    }

    private function resolveMediaPath(?MediaAsset $mediaAsset): ?string
    {
        if ($mediaAsset === null) {
            return null;
        }

        $variants = $mediaAsset->getVariants();
        $thumb = is_array($variants) && isset($variants['thumb']) && is_array($variants['thumb'])
            ? $variants['thumb']
            : [];

        return (is_string($thumb['fallback'] ?? null) ? $thumb['fallback'] : null)
            ?: $mediaAsset->getThumbnailPath()
            ?: $mediaAsset->getExternalUrl()
            ?: $this->specialImageFilePath($mediaAsset)
            ?: self::IMAGE_PLACEHOLDER;
    }

    private function specialImageFilePath(MediaAsset $mediaAsset): ?string
    {
        return $mediaAsset->getMediaType() === MediaType::Image
            && $mediaAsset->getImageType() !== null
            && $mediaAsset->getImageType() !== ImageType::Standard
            ? $mediaAsset->getFilePath()
            : null;
    }
}
