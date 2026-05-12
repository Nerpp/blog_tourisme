<?php

namespace App\Controller\Admin;

use App\Repository\MediaAssetRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminMediaController extends AbstractController
{
    #[Route('/admin/media', name: 'admin_media_index', methods: ['GET'])]
    public function index(MediaAssetRepository $mediaAssetRepository): Response
    {
        return $this->render('admin/media/index.html.twig', [
            'media_assets' => $mediaAssetRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }
}
