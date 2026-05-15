<?php

namespace App\Controller;

use App\Repository\CityVisitDraftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CityVisitController extends AbstractController
{
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
}