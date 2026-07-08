<?php

namespace App\Controller;

use App\Entity\HikeDraft;
use App\Repository\HikeDraftRepository;
use App\Service\Hike\HikeGpxExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HikeController extends AbstractController
{
    private const int LIST_LIMIT = 24;
    private const int SUGGESTION_LIMIT = 8;
    private const int SUGGESTION_MIN_LENGTH = 2;
    private const int QUERY_MAX_LENGTH = 80;

    #[Route('/randonnees', name: 'app_hike_index', methods: ['GET'])]
    public function index(Request $request, HikeDraftRepository $hikeDraftRepository): Response
    {
        $query = $this->searchQuery($request);

        return $this->render('hike/index.html.twig', [
            'hikes' => $hikeDraftRepository->findPublicForListing($query, self::LIST_LIMIT),
            'search_query' => $query,
        ]);
    }

    #[Route('/randonnees/suggestions', name: 'app_hike_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, HikeDraftRepository $hikeDraftRepository): JsonResponse
    {
        $query = $this->searchQuery($request);

        if (mb_strlen($query) < self::SUGGESTION_MIN_LENGTH) {
            return new JsonResponse(['suggestions' => []]);
        }

        /** @var list<array{title: string, url: string, type: string, meta: string}> $suggestions */
        $suggestions = array_map(
            fn (HikeDraft $hike): array => [
                'title' => (string) $hike->getTitle(),
                'url' => $this->generateUrl('app_hike_show', ['slug' => (string) $hike->getSlug()]),
                'type' => 'Randonnée',
                'meta' => $this->hikeMeta($hike),
            ],
            $hikeDraftRepository->findPublicSuggestions($query, self::SUGGESTION_LIMIT),
        );

        return new JsonResponse(['suggestions' => $suggestions]);
    }

    #[Route('/randonnees/{slug}', name: 'app_hike_show', methods: ['GET'])]
    public function show(string $slug, HikeDraftRepository $hikeDraftRepository): Response
    {
        $hike = $hikeDraftRepository->findPublicBySlug($slug);

        if ($hike === null) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        return $this->render('hike/show.html.twig', [
            'hike' => $hike,
        ]);
    }

    #[Route('/randonnees/{slug}/gpx', name: 'app_hike_gpx', methods: ['GET'])]
    public function gpx(string $slug, HikeDraftRepository $hikeDraftRepository, HikeGpxExporter $gpxExporter): Response
    {
        $hike = $hikeDraftRepository->findPublicBySlug($slug);

        if ($hike === null || !$gpxExporter->isAvailable($hike)) {
            throw $this->createNotFoundException('Export GPX indisponible pour cette randonnée.');
        }

        $filename = $gpxExporter->filename($hike);
        $response = new Response($gpxExporter->export($hike));
        $response->headers->set('Content-Type', 'application/gpx+xml; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition('attachment', $filename));

        return $response;
    }

    private function searchQuery(Request $request): string
    {
        $query = trim($request->query->getString('q'));

        return mb_substr($query, 0, self::QUERY_MAX_LENGTH);
    }

    private function hikeMeta(HikeDraft $hike): string
    {
        $destinationName = $hike->getGeographicDestination()?->getName()
            ?? $hike->getDestination()?->getName()
            ?? $hike->getDetectedCommuneName();

        return $destinationName === null || $destinationName === ''
            ? 'Randonnée'
            : 'Randonnée · '.$destinationName;
    }
}
