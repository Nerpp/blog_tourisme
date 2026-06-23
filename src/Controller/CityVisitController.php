<?php

namespace App\Controller;

use App\Entity\CityVisitDraft;
use App\Repository\CityVisitDraftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CityVisitController extends AbstractController
{
    private const int LIST_LIMIT = 24;
    private const int SUGGESTION_LIMIT = 8;
    private const int SUGGESTION_MIN_LENGTH = 2;
    private const int QUERY_MAX_LENGTH = 80;

    #[Route('/visites', name: 'app_city_visit_index', methods: ['GET'])]
    public function index(Request $request, CityVisitDraftRepository $cityVisitDraftRepository): Response
    {
        $query = $this->searchQuery($request);

        return $this->render('city_visit/index.html.twig', [
            'city_visits' => $cityVisitDraftRepository->findPublicForListing($query, self::LIST_LIMIT),
            'search_query' => $query,
        ]);
    }

    #[Route('/visites/suggestions', name: 'app_city_visit_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, CityVisitDraftRepository $cityVisitDraftRepository): JsonResponse
    {
        $query = $this->searchQuery($request);

        if (mb_strlen($query) < self::SUGGESTION_MIN_LENGTH) {
            return new JsonResponse(['suggestions' => []]);
        }

        /** @var list<array{title: string, url: string, type: string, meta: string}> $suggestions */
        $suggestions = array_map(
            fn (CityVisitDraft $cityVisit): array => [
                'title' => (string) $cityVisit->getTitle(),
                'url' => $this->generateUrl('app_city_visit_show', ['slug' => (string) $cityVisit->getSlug()]),
                'type' => 'Visite',
                'meta' => $this->cityVisitMeta($cityVisit),
            ],
            $cityVisitDraftRepository->findPublicSuggestions($query, self::SUGGESTION_LIMIT),
        );

        return new JsonResponse(['suggestions' => $suggestions]);
    }

    #[Route('/visites-de-ville/{slug}', name: 'app_city_visit_show', methods: ['GET'])]
    public function show(string $slug, CityVisitDraftRepository $cityVisitDraftRepository): Response
    {
        $cityVisit = $cityVisitDraftRepository->findPublicBySlug($slug);

        if ($cityVisit === null) {
            throw $this->createNotFoundException('Visite de ville introuvable.');
        }

        return $this->render('city_visit/show.html.twig', [
            'city_visit' => $cityVisit,
        ]);
    }

    private function searchQuery(Request $request): string
    {
        $query = trim($request->query->getString('q'));

        return mb_substr($query, 0, self::QUERY_MAX_LENGTH);
    }

    private function cityVisitMeta(CityVisitDraft $cityVisit): string
    {
        $destinationName = $cityVisit->getGeographicDestination()?->getName()
            ?? $cityVisit->getDestination()?->getName()
            ?? $cityVisit->getDetectedCommuneName();

        return $destinationName === null || $destinationName === ''
            ? 'Visite'
            : 'Visite · '.$destinationName;
    }
}
