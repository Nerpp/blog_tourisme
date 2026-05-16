<?php

namespace App\Controller;

use App\Repository\DestinationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DestinationController extends AbstractController
{
    #[Route('/destinations', name: 'app_destination_index', methods: ['GET'])]
    public function index(DestinationRepository $destinationRepository): Response
    {
        $rootDestinations = $destinationRepository->findRootDestinations();

        return $this->render('destination/index.html.twig', [
            'root_destinations' => $rootDestinations,
            'destination_counts' => $destinationRepository->findCumulativeContentCountsForTree($rootDestinations),
        ]);
    }

    #[Route('/destinations/{slug}', name: 'app_destination_show', methods: ['GET'])]
    public function show(string $slug, DestinationRepository $destinationRepository): Response
    {
        $destination = $destinationRepository->findBySlug($slug);
        if ($destination === null) {
            throw $this->createNotFoundException('Destination introuvable.');
        }

        return $this->render('destination/show.html.twig', [
            'destination' => $destination,
        ]);
    }
}
