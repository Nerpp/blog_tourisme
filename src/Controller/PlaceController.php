<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Form\CommentType;
use App\Repository\CommentRepository;
use App\Repository\PlaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlaceController extends AbstractController
{
    #[Route('/places/{slug}', name: 'app_place_show', methods: ['GET'])]
    public function show(string $slug, PlaceRepository $placeRepository, CommentRepository $commentRepository): Response
    {
        $place = $placeRepository->findOneBy(['slug' => $slug]);
        if ($place === null) {
            throw $this->createNotFoundException('Lieu introuvable.');
        }

        $commentForm = $this->getUser() === null
            ? null
            : $this->createForm(CommentType::class, new Comment(), [
                'action' => $this->generateUrl('app_place_comment_create', ['slug' => $place->getSlug()]),
            ])->createView();

        return $this->render('place/show.html.twig', [
            'place' => $place,
            'comments' => $commentRepository->findApprovedForPlace($place),
            'comment_form' => $commentForm,
        ]);
    }
}
