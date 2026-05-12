<?php

namespace App\Controller\Admin;

use App\Repository\ArticleRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\CommentRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Repository\MediaAssetRepository;
use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminDashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin', methods: ['GET'])]
    public function index(
        ArticleRepository $articleRepository,
        PlaceRepository $placeRepository,
        DestinationRepository $destinationRepository,
        MediaAssetRepository $mediaAssetRepository,
        CommentRepository $commentRepository,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
        UserRepository $userRepository,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'counts' => [
                'articles' => $articleRepository->count([]),
                'places' => $placeRepository->count([]),
                'destinations' => $destinationRepository->count([]),
                'media' => $mediaAssetRepository->count([]),
                'comments' => $commentRepository->count([]),
                'hikes' => $hikeDraftRepository->count([]),
                'city_visits' => $cityVisitDraftRepository->count([]),
                'users' => $userRepository->count([]),
            ],
        ]);
    }
}
