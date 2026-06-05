<?php

namespace App\Controller\Admin;

use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Service\Weather\QfeCalculator;
use App\Service\Weather\QnhProvider;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminFieldToolsController extends AbstractController
{
    #[Route('/admin/field-tools', name: 'admin_field_tools_index', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('admin_studio_index');
    }

    #[Route('/admin/field-tools/hikes', name: 'admin_field_tools_hikes', methods: ['GET'])]
    public function hikes(HikeDraftRepository $hikeDraftRepository): Response
    {
        return $this->render('admin/field_tools/hikes.html.twig', [
            'hikes' => $hikeDraftRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }

    #[Route('/admin/field-tools/city-visits', name: 'admin_field_tools_city_visits', methods: ['GET'])]
    public function cityVisits(CityVisitDraftRepository $cityVisitDraftRepository): Response
    {
        return $this->render('admin/field_tools/city_visits.html.twig', [
            'city_visits' => $cityVisitDraftRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }

    #[Route('/admin/outils-terrain/gps', name: 'admin_field_tools_gps', methods: ['GET'])]
    public function gps(): Response
    {
        return $this->render('admin/field_tool/gps.html.twig');
    }

    #[Route('/admin/outils-terrain/qnh', name: 'admin_field_tools_qnh', methods: ['GET'])]
    public function qnh(Request $request, QnhProvider $qnhProvider, QfeCalculator $qfeCalculator): JsonResponse
    {
        $latitude = $this->coordinateFromQuery($request, 'latitude');
        $longitude = $this->coordinateFromQuery($request, 'longitude');
        $altitude = $this->optionalFloatFromQuery($request, 'altitude');
        $altitudeSource = $this->altitudeSourceFromQuery($request);

        if ($latitude === null || $longitude === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Latitude et longitude valides sont obligatoires.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $qnhProvider->provide($latitude, $longitude);
        } catch (InvalidArgumentException $exception) {
            return $this->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($result['ok'] === true && isset($result['qnhHpa'])) {
            $qnhHpa = (float) $result['qnhHpa'];
            $qfeHpa = $altitude !== null ? $qfeCalculator->calculate($qnhHpa, $altitude) : null;

            $result['altitudeMeters'] = $altitude;
            $result['altitudeSource'] = $altitude !== null ? $altitudeSource : null;
            $result['qfeHpa'] = $qfeHpa;
            $result['message'] = $altitude !== null
                ? 'QNH récupéré et QFE calculé à partir de l’altitude utilisée.'
                : 'QNH récupéré. Hauteur GPS indisponible : QFE non calculable automatiquement.';
            $result['summary'] = $qfeHpa !== null
                ? sprintf('QNH %d hPa - Altitude %s m - QFE conseillé Skywatch page 6 : %d hPa', (int) $result['qnhHpa'], $this->formatNumber($altitude), $qfeHpa)
                : sprintf('QNH %d hPa - Altitude indisponible - QFE non calculable', (int) $result['qnhHpa']);
        }

        return $this->json($result, $result['ok'] === true ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function coordinateFromQuery(Request $request, string $name): ?float
    {
        $value = trim($request->query->getString($name));
        if ($value === '') {
            return null;
        }

        $normalizedValue = str_replace(',', '.', $value);
        if (!is_numeric($normalizedValue)) {
            return null;
        }

        return (float) $normalizedValue;
    }

    private function optionalFloatFromQuery(Request $request, string $name): ?float
    {
        $value = trim($request->query->getString($name));
        if ($value === '') {
            return null;
        }

        $normalizedValue = str_replace(',', '.', $value);
        if (!is_numeric($normalizedValue)) {
            return null;
        }

        return (float) $normalizedValue;
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function altitudeSourceFromQuery(Request $request): string
    {
        $source = $request->query->getString('altitudeSource');

        return $source === 'manual' ? 'manual' : 'gps';
    }
}
