<?php

namespace App\Controller;

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

        return $this->render('destination/show.html.twig', [
            'destination' => $destination,
            'articles' => $articleRepository->findPublishedByDestinationIds($destinationIds),
            'hikes' => $hikeDraftRepository->findPublicByDestinationIds($destinationIds),
            'city_visits' => $cityVisitDraftRepository->findPublicByDestinationIds($destinationIds),
        ]);
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
