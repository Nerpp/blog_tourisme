<?php

namespace App\Controller;

use App\Repository\DestinationRepository;
use App\Service\HomepageDestinationMediaResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\HomepageLatestContentProvider;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(
        DestinationRepository $destinationRepository,
        HomepageLatestContentProvider $homepageLatestContentProvider,
        HomepageDestinationMediaResolver $destinationMediaResolver,
    ): Response {
        $destinations = $destinationRepository->findDiscoverableDestinations(6);
        $destinationCards = array_map(
            static fn ($destination): array => [
                'destination' => $destination,
                'media' => $destinationMediaResolver->representativeMedia($destination),
            ],
            $destinations,
        );

        return $this->render('home/index.html.twig', [
            'latest_homepage_item' => $homepageLatestContentProvider->getLatest(),
            'destination_cards' => $destinationCards,
        ]);
    }
}
