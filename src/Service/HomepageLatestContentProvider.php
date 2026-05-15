<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;


final readonly class HomepageLatestContentProvider
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private HikeDraftRepository $hikeDraftRepository,
        private CityVisitDraftRepository $cityVisitDraftRepository,
        private PublicContentUrlResolver $urlResolver,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     title: string,
     *     url: string,
     *     image: ?string,
     *     date: \DateTimeImmutable
     * }|null
     */
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

    private function normalizeArticle(?Article $article): ?array
    {
        if ($article === null || $article->getPublishedAt() === null) {
            return null;
        }

        $url = $this->urlResolver->articleUrl($article);

        if ($url === null) {
            return null;
        }

        $media = $article->getFeaturedImage() ?? $this->getFirstArticleMedia($article);

        return [
            'type' => 'article',
            'label' => 'Dernier article',
            'title' => $article->getTitle() ?? 'Article',
            'url' => $url,
            'image' => $this->resolveMediaPath($media),
            'date' => $article->getPublishedAt(),
        ];
    }

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
            'image' => $this->resolveMediaPath($this->getFirstHikeMedia($hike)),
            'date' => $hike->getFinishedAt(),
        ];
    }

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
            'image' => $this->resolveMediaPath($this->getFirstCityVisitMedia($cityVisit)),
            'date' => $cityVisit->getFinishedAt(),
        ];
    }

    private function getFirstArticleMedia(Article $article): ?MediaAsset
    {
        $firstMediaLink = $article->getMediaLinks()->first();

        if ($firstMediaLink === false) {
            return null;
        }

        return $firstMediaLink->getMediaAsset();
    }

    private function getFirstHikeMedia(HikeDraft $hike): ?MediaAsset
    {
        $firstMediaLink = $hike->getMediaLinks()->first();

        if ($firstMediaLink === false) {
            return null;
        }

        return $firstMediaLink->getMediaAsset();
    }

    private function getFirstCityVisitMedia(CityVisitDraft $cityVisit): ?MediaAsset
    {
        $firstMediaLink = $cityVisit->getMediaLinks()->first();

        if ($firstMediaLink === false) {
            return null;
        }

        return $firstMediaLink->getMediaAsset();
    }

    private function resolveMediaPath(?MediaAsset $mediaAsset): ?string
    {
        if ($mediaAsset === null) {
            return null;
        }

        return $mediaAsset->getThumbnailPath()
            ?: $mediaAsset->getFilePath()
            ?: $mediaAsset->getExternalUrl();
    }
}
