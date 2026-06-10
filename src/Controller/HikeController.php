<?php

namespace App\Controller;

use App\Repository\HikeDraftRepository;
use App\Service\Hike\HikeGpxExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HikeController extends AbstractController
{
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
}
