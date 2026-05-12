<?php

namespace App\Controller\Admin;

use App\Repository\DestinationRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminPlaceController extends AbstractController
{
    #[Route('/admin/places', name: 'admin_places_index', methods: ['GET'])]
    public function places(PlaceRepository $placeRepository): Response
    {
        return $this->render('admin/places/index.html.twig', [
            'places' => $placeRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }

    #[Route('/admin/destinations', name: 'admin_destinations_index', methods: ['GET'])]
    public function destinations(DestinationRepository $destinationRepository): Response
    {
        return $this->render('admin/destinations/index.html.twig', [
            'destinations' => $destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC'], 100),
        ]);
    }
}
