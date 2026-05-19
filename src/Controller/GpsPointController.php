<?php

namespace App\Controller;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Enum\CityVisitDraftStatus;
use App\Enum\HikeDraftStatus;
use App\Repository\CityVisitDraftRepository;
use App\Repository\CityVisitPointRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\HikePointRepository;
use App\Security\Voter\GpsAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GpsPointController extends AbstractController
{
    private const MAX_WAYPOINTS = 20;

    #[Route(
        '/gps/points/{type}/{id}/open',
        name: 'app_gps_point_open',
        requirements: ['type' => 'hike|city_visit', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function open(
        string $type,
        int $id,
        HikePointRepository $hikePointRepository,
        CityVisitPointRepository $cityVisitPointRepository,
    ): RedirectResponse {
        $point = match ($type) {
            'hike' => $hikePointRepository->find($id),
            'city_visit' => $cityVisitPointRepository->find($id),
        };

        if (!$point instanceof HikePoint && !$point instanceof CityVisitPoint) {
            throw $this->createNotFoundException('Point GPS introuvable.');
        }

        if (!$this->isPublicPoint($point)) {
            throw $this->createNotFoundException('Point GPS introuvable.');
        }

        $this->denyAccessUnlessGranted(GpsAccessVoter::GPS_ACCESS, $point);

        $latitude = $point->getLatitude();
        $longitude = $point->getLongitude();

        if ($latitude === null || $longitude === null) {
            throw $this->createNotFoundException('Coordonnées GPS indisponibles.');
        }

        return $this->redirect(sprintf(
            'https://www.google.com/maps/search/?api=1&query=%s,%s',
            $latitude,
            $longitude,
        ));
    }

    #[Route(
        '/gps/{type}/{id}/start',
        name: 'app_gps_start_open',
        requirements: ['type' => 'hike|city_visit', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function start(
        string $type,
        int $id,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): RedirectResponse {
        $content = $this->findPublicContent($type, $id, $hikeDraftRepository, $cityVisitDraftRepository);
        $points = $this->validGpsPoints($content);
        $startPoint = $points[0] ?? null;

        if (!$startPoint instanceof HikePoint && !$startPoint instanceof CityVisitPoint) {
            throw $this->createNotFoundException('Aucun point GPS disponible.');
        }

        $this->denyAccessUnlessGranted(GpsAccessVoter::GPS_ACCESS, $startPoint);

        return $this->redirect($this->googleMapsDirectionsUrl(destination: $startPoint));
    }

    #[Route(
        '/gps/{type}/{id}/route',
        name: 'app_gps_route_open',
        requirements: ['type' => 'hike|city_visit', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function route(
        string $type,
        int $id,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): RedirectResponse {
        $content = $this->findPublicContent($type, $id, $hikeDraftRepository, $cityVisitDraftRepository);
        $points = $this->validGpsPoints($content);
        $startPoint = $points[0] ?? null;

        if (!$startPoint instanceof HikePoint && !$startPoint instanceof CityVisitPoint) {
            throw $this->createNotFoundException('Aucun point GPS disponible.');
        }

        $this->denyAccessUnlessGranted(GpsAccessVoter::GPS_ACCESS, $startPoint);

        if (count($points) === 1) {
            return $this->redirect($this->googleMapsDirectionsUrl(destination: $startPoint));
        }

        $endPoint = $points[array_key_last($points)] ?? null;
        if (!$endPoint instanceof HikePoint && !$endPoint instanceof CityVisitPoint) {
            throw $this->createNotFoundException('Aucun point GPS disponible.');
        }

        $waypoints = array_slice($points, 1, -1);

        // Google Maps URLs become fragile when too many waypoints are sent.
        $waypoints = array_slice($waypoints, 0, self::MAX_WAYPOINTS);

        return $this->redirect($this->googleMapsDirectionsUrl(
            origin: $startPoint,
            destination: $endPoint,
            waypoints: $waypoints,
        ));
    }

    private function isPublicPoint(HikePoint|CityVisitPoint $point): bool
    {
        if ($point instanceof HikePoint) {
            $hike = $point->getHikeDraft();

            return $hike !== null
                && in_array($hike->getStatus(), [HikeDraftStatus::Finished, HikeDraftStatus::Converted], true);
        }

        $cityVisit = $point->getCityVisitDraft();

        return $cityVisit !== null
            && in_array($cityVisit->getStatus(), [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted], true);
    }

    private function findPublicContent(
        string $type,
        int $id,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): HikeDraft|CityVisitDraft {
        $content = match ($type) {
            'hike' => $hikeDraftRepository->find($id),
            'city_visit' => $cityVisitDraftRepository->find($id),
        };

        if (!$content instanceof HikeDraft && !$content instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Contenu introuvable.');
        }

        if (!$this->isPublicContent($content)) {
            throw $this->createNotFoundException('Contenu introuvable.');
        }

        return $content;
    }

    private function isPublicContent(HikeDraft|CityVisitDraft $content): bool
    {
        if ($content instanceof HikeDraft) {
            return in_array($content->getStatus(), [HikeDraftStatus::Finished, HikeDraftStatus::Converted], true);
        }

        return in_array($content->getStatus(), [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted], true);
    }

    /** @return list<HikePoint|CityVisitPoint> */
    private function validGpsPoints(HikeDraft|CityVisitDraft $content): array
    {
        $points = [];

        foreach ($content->getPoints() as $point) {
            if (($point instanceof HikePoint || $point instanceof CityVisitPoint)
                && $point->getLatitude() !== null
                && $point->getLongitude() !== null
            ) {
                $points[] = $point;
            }
        }

        usort(
            $points,
            static fn (HikePoint|CityVisitPoint $first, HikePoint|CityVisitPoint $second): int => $first->getPosition() <=> $second->getPosition()
                ?: ($first->getId() ?? 0) <=> ($second->getId() ?? 0),
        );

        return $points;
    }

    /**
     * @param list<HikePoint|CityVisitPoint> $waypoints
     */
    private function googleMapsDirectionsUrl(
        HikePoint|CityVisitPoint|null $origin = null,
        HikePoint|CityVisitPoint|null $destination = null,
        array $waypoints = [],
    ): string {
        $parameters = [
            'api' => '1',
            'travelmode' => 'walking',
        ];

        if ($origin instanceof HikePoint || $origin instanceof CityVisitPoint) {
            $parameters['origin'] = $this->formatPointCoordinates($origin);
        }

        if ($destination instanceof HikePoint || $destination instanceof CityVisitPoint) {
            $parameters['destination'] = $this->formatPointCoordinates($destination);
        }

        if ($waypoints !== []) {
            $parameters['waypoints'] = implode('|', array_map(
                fn (HikePoint|CityVisitPoint $waypoint): string => $this->formatPointCoordinates($waypoint),
                $waypoints,
            ));
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function formatPointCoordinates(HikePoint|CityVisitPoint $point): string
    {
        return sprintf('%s,%s', $point->getLatitude(), $point->getLongitude());
    }
}
