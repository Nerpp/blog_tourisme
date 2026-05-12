<?php

namespace App\Controller\Admin;

use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminFieldToolsController extends AbstractController
{
    #[Route('/admin/field-tools', name: 'admin_field_tools_index', methods: ['GET'])]
    public function index(
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): Response {
        return $this->render('admin/field_tools/index.html.twig', [
            'latest_hikes' => $hikeDraftRepository->findBy([], ['createdAt' => 'DESC'], 5),
            'latest_city_visits' => $cityVisitDraftRepository->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }

    #[Route('/admin/field-tools/hikes', name: 'admin_field_tools_hikes', methods: ['GET'])]
    public function hikes(HikeDraftRepository $hikeDraftRepository): Response
    {
        return $this->render('admin/field_tools/hikes.html.twig', [
            'hikes' => $hikeDraftRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }

    #[Route('/admin/field-tools/city-visits', name: 'admin_field_tools_city_visits', methods: ['GET'])]
    public function cityVisits(CityVisitDraftRepository $cityVisitDraftRepository): Response
    {
        return $this->render('admin/field_tools/city_visits.html.twig', [
            'city_visits' => $cityVisitDraftRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }
}
