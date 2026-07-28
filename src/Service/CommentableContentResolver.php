<?php

namespace App\Service;

use App\Contract\CommentableContentInterface;
use App\Entity\CommentThread;
use App\Enum\CommentableType;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\PlaceRepository;

final class CommentableContentResolver
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly PlaceRepository $placeRepository,
        private readonly HikeDraftRepository $hikeRepository,
        private readonly CityVisitDraftRepository $cityVisitRepository,
    ) {
    }

    public function resolvePublic(CommentableType $type, string $slug): ?CommentableContentInterface
    {
        $content = match ($type) {
            CommentableType::Article => $this->articleRepository->findPublishedBySlug($slug),
            CommentableType::Place => $this->placeRepository->findPublishedBySlug($slug),
            CommentableType::Hike => $this->hikeRepository->findPublicBySlug($slug),
            CommentableType::CityVisit => $this->cityVisitRepository->findPublicBySlug($slug),
        };

        return $content instanceof CommentableContentInterface && $content->isPublished()
            ? $content
            : null;
    }

    public function resolvePublicThread(CommentThread $thread): ?CommentableContentInterface
    {
        $content = $thread->getContent();

        if (
            !$content instanceof CommentableContentInterface
            || $content->getCommentableType() !== $thread->getContentType()
            || !$content->isPublished()
        ) {
            return null;
        }

        return $content;
    }
}
