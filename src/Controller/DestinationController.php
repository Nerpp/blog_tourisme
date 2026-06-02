<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Enum\CityVisitDraftStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DestinationController extends AbstractController
{
    #[Route('/destinations', name: 'app_destination_index', methods: ['GET'])]
    public function index(DestinationRepository $destinationRepository): Response
    {
        $rootDestinations = $destinationRepository->findRootDestinations();
        $destinationCounts = $destinationRepository->findCumulativeContentCountsForTree($rootDestinations);

        return $this->render('destination/index.html.twig', [
            'root_destinations' => $rootDestinations,
            'destination_counts' => $destinationCounts,
            'destination_summary' => $this->summarizeRootDestinationCounts($rootDestinations, $destinationCounts),
            'destination_suggestions' => $destinationRepository->findDestinationSuggestionsForTree($rootDestinations),
        ]);
    }

    #[Route('/destinations/{slug}', name: 'app_destination_show', methods: ['GET'])]
    public function show(
        string $slug,
        DestinationRepository $destinationRepository,
        ArticleRepository $articleRepository,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): Response {
        $destination = $destinationRepository->findBySlug($slug);
        if ($destination === null) {
            throw $this->createNotFoundException('Destination introuvable.');
        }

        $destinationIds = $destinationRepository->findDestinationAndDescendantIds($destination);
        $contentDestinationIds = $this->contentDestinationIds($destination, $destinationIds);
        $destinationsInScope = $destinationRepository->findWithParentsByIds($destinationIds);
        $departmentByDestinationId = $this->departmentByDestinationId($destination, $destinationsInScope);
        $departmentContextByDestinationId = $this->departmentContextByDestinationId($departmentByDestinationId);
        $exploreDestinations = $this->exploreDestinations($destination);
        $isContentLevel = $this->isContentLevel($destination);
        $articles = $isContentLevel ? $articleRepository->findPublishedByDestinationIds($contentDestinationIds) : [];
        $hikes = $isContentLevel ? $hikeDraftRepository->findPublicByDestinationIds($contentDestinationIds) : [];
        $cityVisits = $isContentLevel ? $cityVisitDraftRepository->findPublicByDestinationIds($contentDestinationIds) : [];
        $articleCards = $this->destinationArticleCards($articles, $destination, $contentDestinationIds, $departmentByDestinationId);

        return $this->render('destination/show.html.twig', [
            'destination' => $destination,
            'article_cards' => $articleCards,
            'hikes' => $hikes,
            'city_visits' => $cityVisits,
            'destination_ids' => $destinationIds,
            'explore_destinations' => $exploreDestinations,
            'child_explore_counts' => $this->childExploreCounts($exploreDestinations, $destinationsInScope),
            'department_context_by_destination_id' => $departmentContextByDestinationId,
            'department_summaries' => $this->departmentSummaries(
                $destination,
                $exploreDestinations,
                $destinationsInScope,
                $departmentByDestinationId,
                $articleCards,
                $hikes,
                $cityVisits,
            ),
        ]);
    }

    /**
     * @param list<Article> $articles
     * @param list<int>    $destinationIds
     *
     * @return list<array{
     *     article: Article,
     *     context_label: string,
     *     context_title: string|null,
     *     role_label: string|null,
     *     department_id: int|null,
     *     department_name: string|null,
     *     department_url: string|null,
     *     search_text: string
     * }>
     */
    private function destinationArticleCards(array $articles, Destination $currentDestination, array $destinationIds, array $departmentByDestinationId): array
    {
        $cards = [];
        $seen = [];
        $destinationIds = array_fill_keys($destinationIds, true);

        foreach ($articles as $article) {
            $articleId = $article->getId();
            if ($articleId !== null && isset($seen[$articleId])) {
                continue;
            }

            if ($articleId !== null) {
                $seen[$articleId] = true;
            }

            $context = $this->articleContext($article, $currentDestination, $destinationIds);
            $department = $this->departmentForDestination($context['context_destination'], $departmentByDestinationId);
            $searchParts = [
                $article->getTitle(),
                $article->getExcerpt(),
                $article->getCategory()?->getName(),
                $context['context_label'],
                $context['context_title'],
                $context['role_label'],
                $department?->getName(),
            ];

            $cards[] = [
                'article' => $article,
                'context_label' => $context['context_label'],
                'context_title' => $context['context_title'],
                'role_label' => $context['role_label'],
                'department_id' => $department?->getId(),
                'department_name' => $department?->getName(),
                'department_url' => $department?->getSlug() ? $this->generateUrl('app_destination_show', ['slug' => $department->getSlug()]) : null,
                'search_text' => trim(implode(' ', array_filter($searchParts, static fn (?string $part): bool => $part !== null && $part !== ''))),
            ];
        }

        return $cards;
    }

    /**
     * @param array<int, true> $destinationIds
     *
     * @return array{context_label: string, context_title: string|null, role_label: string|null, context_destination: Destination|null}
     */
    private function articleContext(Article $article, Destination $currentDestination, array $destinationIds): array
    {
        foreach ($article->getDestinationLinks() as $link) {
            $destination = $link->getDestination();
            if (!$this->destinationMatches($destination, $destinationIds)) {
                continue;
            }

            $context = $destination === $currentDestination
                ? sprintf('Article lié à %s', $destination->getName())
                : sprintf('Article lié à la destination %s', $destination->getName());

            return [
                'context_label' => $context,
                'context_title' => $destination->getName(),
                'role_label' => null,
                'context_destination' => $destination,
            ];
        }

        foreach ($article->getHikeLinks() as $link) {
            $hike = $link->getHikeDraft();
            if (!$hike instanceof HikeDraft || !$this->isPublicHike($hike) || !$this->destinationMatches($hike->getDestination(), $destinationIds)) {
                continue;
            }

            return [
                'context_label' => sprintf('Article lié à la randonnée %s', $hike->getTitle()),
                'context_title' => $hike->getTitle(),
                'role_label' => $this->roleLabel($link->getRole()),
                'context_destination' => $hike->getDestination(),
            ];
        }

        foreach ($article->getCityVisitLinks() as $link) {
            $cityVisit = $link->getCityVisitDraft();
            if (!$cityVisit instanceof CityVisitDraft || !$this->isPublicCityVisit($cityVisit) || !$this->destinationMatches($cityVisit->getDestination(), $destinationIds)) {
                continue;
            }

            return [
                'context_label' => sprintf('Article lié à la visite %s', $cityVisit->getTitle()),
                'context_title' => $cityVisit->getTitle(),
                'role_label' => $this->roleLabel($link->getRole()),
                'context_destination' => $cityVisit->getDestination(),
            ];
        }

        return [
            'context_label' => 'Article lié à cette destination',
            'context_title' => $currentDestination->getName(),
            'role_label' => null,
            'context_destination' => $currentDestination,
        ];
    }

    /**
     * @param list<int> $destinationIds
     *
     * @return list<int>
     */
    private function contentDestinationIds(Destination $destination, array $destinationIds): array
    {
        if (!$this->isContentLevel($destination)) {
            return [];
        }

        return $destinationIds;
    }

    private function isContentLevel(Destination $destination): bool
    {
        return in_array($destination->getType(), [DestinationType::City, DestinationType::Area], true);
    }

    /**
     * @param list<Destination> $children
     * @param list<Destination> $destinationsInScope
     *
     * @return array<int, array{count: int, label: string}>
     */
    private function childExploreCounts(array $children, array $destinationsInScope): array
    {
        $counts = [];

        foreach ($children as $child) {
            $childId = $child->getId();
            if ($childId === null) {
                continue;
            }

            $count = 0;
            foreach ($destinationsInScope as $candidate) {
                $parentId = $candidate->getParent()?->getId();
                if ($parentId === $childId && $this->isExpectedChildLevel($child, $candidate)) {
                    ++$count;
                }
            }

            $counts[$childId] = [
                'count' => $count,
                'label' => $this->childExploreLabel($child, $count),
            ];
        }

        return $counts;
    }

    private function isExpectedChildLevel(Destination $parent, Destination $candidate): bool
    {
        return match ($parent->getType()) {
            DestinationType::Country => $candidate->getType() === DestinationType::Region,
            DestinationType::Region => $candidate->getType() === DestinationType::Department,
            DestinationType::Department => in_array($candidate->getType(), [DestinationType::City, DestinationType::Area], true),
            default => true,
        };
    }

    private function childExploreLabel(Destination $destination, int $count): string
    {
        return match ($destination->getType()) {
            DestinationType::Country => sprintf('%d région%s à explorer', $count, $count > 1 ? 's' : ''),
            DestinationType::Region => sprintf('%d département%s à explorer', $count, $count > 1 ? 's' : ''),
            DestinationType::Department => sprintf('%d lieu%s à découvrir', $count, $count > 1 ? 'x' : ''),
            default => sprintf('%d lieu%s à découvrir', $count, $count > 1 ? 'x' : ''),
        };
    }

    /**
     * @return list<Destination>
     */
    private function exploreDestinations(Destination $destination): array
    {
        $children = $destination->getChildren()->toArray();

        if ($destination->getType() !== DestinationType::Region) {
            return $children;
        }

        return array_values(array_filter(
            $children,
            static fn (Destination $child): bool => $child->getType() === DestinationType::Department,
        ));
    }

    /**
     * @param list<Destination> $destinations
     *
     * @return array<int, Destination>
     */
    private function departmentByDestinationId(Destination $currentDestination, array $destinations): array
    {
        $map = [];

        foreach ($destinations as $destination) {
            $destinationId = $destination->getId();
            $department = $this->nearestDepartment($destination);

            if ($destinationId !== null && $department instanceof Destination) {
                $map[$destinationId] = $department;
            }
        }

        $currentId = $currentDestination->getId();
        if ($currentId !== null && $currentDestination->getType() === DestinationType::Department) {
            $map[$currentId] = $currentDestination;
        }

        return $map;
    }

    private function nearestDepartment(?Destination $destination): ?Destination
    {
        $cursor = $destination;

        while ($cursor instanceof Destination) {
            if ($cursor->getType() === DestinationType::Department) {
                return $cursor;
            }

            $cursor = $cursor->getParent();
        }

        return null;
    }

    /**
     * @param array<int, Destination> $departmentByDestinationId
     *
     * @return array<int, array{id: int, name: string, url: string}>
     */
    private function departmentContextByDestinationId(array $departmentByDestinationId): array
    {
        $context = [];

        foreach ($departmentByDestinationId as $destinationId => $department) {
            $departmentId = $department->getId();
            $departmentSlug = $department->getSlug();

            if ($departmentId === null || $departmentSlug === null) {
                continue;
            }

            $context[$destinationId] = [
                'id' => $departmentId,
                'name' => $department->getName() ?? '',
                'url' => $this->generateUrl('app_destination_show', ['slug' => $departmentSlug]),
            ];
        }

        return $context;
    }

    /**
     * @param array<int, Destination> $departmentByDestinationId
     */
    private function departmentForDestination(?Destination $destination, array $departmentByDestinationId): ?Destination
    {
        $destinationId = $destination?->getId();

        if ($destinationId !== null && isset($departmentByDestinationId[$destinationId])) {
            return $departmentByDestinationId[$destinationId];
        }

        return $this->nearestDepartment($destination);
    }

    /**
     * @param list<Destination> $exploreDestinations
     * @param list<Destination> $destinationsInScope
     * @param array<int, Destination> $departmentByDestinationId
     * @param list<array{department_id: int|null}> $articleCards
     * @param list<HikeDraft> $hikes
     * @param list<CityVisitDraft> $cityVisits
     *
     * @return list<array{
     *     destination: Destination,
     *     articles: int,
     *     hikes: int,
     *     city_visits: int,
     *     destinations: int,
     *     total: int
     * }>
     */
    private function departmentSummaries(Destination $currentDestination, array $exploreDestinations, array $destinationsInScope, array $departmentByDestinationId, array $articleCards, array $hikes, array $cityVisits): array
    {
        $departments = $currentDestination->getType() === DestinationType::Department
            ? [$currentDestination]
            : array_values(array_filter(
                $exploreDestinations,
                static fn (Destination $destination): bool => $destination->getType() === DestinationType::Department,
            ));

        $summaries = [];
        foreach ($departments as $department) {
            $departmentId = $department->getId();
            if ($departmentId === null) {
                continue;
            }

            $summaries[$departmentId] = [
                'destination' => $department,
                'articles' => 0,
                'hikes' => 0,
                'city_visits' => 0,
                'destinations' => 0,
                'total' => 0,
            ];
        }

        foreach ($articleCards as $card) {
            $departmentId = $card['department_id'] ?? null;
            if ($departmentId !== null && isset($summaries[$departmentId])) {
                ++$summaries[$departmentId]['articles'];
            }
        }

        foreach ($hikes as $hike) {
            $departmentId = $this->departmentForDestination($hike->getDestination(), $departmentByDestinationId)?->getId();
            if ($departmentId !== null && isset($summaries[$departmentId])) {
                ++$summaries[$departmentId]['hikes'];
            }
        }

        foreach ($cityVisits as $cityVisit) {
            $departmentId = $this->departmentForDestination($cityVisit->getDestination(), $departmentByDestinationId)?->getId();
            if ($departmentId !== null && isset($summaries[$departmentId])) {
                ++$summaries[$departmentId]['city_visits'];
            }
        }

        foreach ($destinationsInScope as $destination) {
            $destinationId = $destination->getId();
            $departmentId = $this->departmentForDestination($destination, $departmentByDestinationId)?->getId();

            if ($destinationId !== null && $departmentId !== null && $destinationId !== $departmentId && isset($summaries[$departmentId])) {
                ++$summaries[$departmentId]['destinations'];
            }
        }

        foreach ($summaries as $departmentId => $summary) {
            $summaries[$departmentId]['total'] = $summary['articles'] + $summary['hikes'] + $summary['city_visits'];
        }

        return array_values($summaries);
    }

    /** @param array<int, true> $destinationIds */
    private function destinationMatches(?Destination $destination, array $destinationIds): bool
    {
        $id = $destination?->getId();

        return $id !== null && isset($destinationIds[$id]);
    }

    private function isPublicHike(HikeDraft $hike): bool
    {
        return in_array($hike->getStatus(), [HikeDraftStatus::Finished, HikeDraftStatus::Converted], true);
    }

    private function isPublicCityVisit(CityVisitDraft $cityVisit): bool
    {
        return in_array($cityVisit->getStatus(), [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted], true);
    }

    private function roleLabel(string $role): ?string
    {
        return [
            'related' => 'Article lié',
            'history' => 'Histoire',
            'legend' => 'Légende',
            'practical' => 'Infos pratiques',
            'context' => 'Récit',
            'seo' => 'Pour aller plus loin',
        ][$role] ?? null;
    }

    /**
     * @param list<\App\Entity\Destination> $rootDestinations
     * @param array<int, array{places: int, articles: int, hikes: int, city_visits: int, total: int}> $destinationCounts
     *
     * @return array{places: int, articles: int, hikes: int, city_visits: int, total: int}
     */
    private function summarizeRootDestinationCounts(array $rootDestinations, array $destinationCounts): array
    {
        $summary = [
            'places' => 0,
            'articles' => 0,
            'hikes' => 0,
            'city_visits' => 0,
            'total' => 0,
        ];

        foreach ($rootDestinations as $destination) {
            $id = $destination->getId();
            if ($id === null || !isset($destinationCounts[$id])) {
                continue;
            }

            $summary['places'] += $destinationCounts[$id]['places'];
            $summary['articles'] += $destinationCounts[$id]['articles'];
            $summary['hikes'] += $destinationCounts[$id]['hikes'];
            $summary['city_visits'] += $destinationCounts[$id]['city_visits'];
        }

        $summary['total'] = $summary['articles'] + $summary['hikes'] + $summary['city_visits'];

        return $summary;
    }
}
