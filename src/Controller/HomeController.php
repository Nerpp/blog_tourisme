<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\DestinationRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(
        ArticleRepository $articleRepository,
        DestinationRepository $destinationRepository,
        PlaceRepository $placeRepository,
    ): Response
    {
        return $this->render('home/index.html.twig', [
            'latest_articles' => $articleRepository->findLatestPublished(3),
            'discoverable_destinations' => $destinationRepository->findDiscoverableDestinations(6),
            'featured_places' => $placeRepository->findFeaturedPublished(3),
        ]);
    }
}
