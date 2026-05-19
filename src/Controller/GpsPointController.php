<?php

namespace App\Controller;

use App\Entity\CityVisitPoint;
use App\Entity\HikePoint;
use App\Enum\CityVisitDraftStatus;
use App\Enum\HikeDraftStatus;
use App\Repository\CityVisitPointRepository;
use App\Repository\HikePointRepository;
use App\Security\Voter\GpsAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GpsPointController extends AbstractController
{
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
}
