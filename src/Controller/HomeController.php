<?php

namespace App\Controller;

//use App\Repository\ArticleRepository;
use App\Repository\DestinationRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\HomepageLatestContentProvider;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(
        DestinationRepository $destinationRepository,
        PlaceRepository $placeRepository,
        HomepageLatestContentProvider $homepageLatestContentProvider,
    ): Response {

        return $this->render('home/index.html.twig', [
            'latest_homepage_item' => $homepageLatestContentProvider->getLatest(),
            'discoverable_destinations' => $destinationRepository->findDiscoverableDestinations(6),
            'featured_places' => $placeRepository->findFeaturedPublished(3),
        ]);
    }
}
