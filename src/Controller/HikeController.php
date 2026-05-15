<?php

namespace App\Controller;

use App\Repository\HikeDraftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}