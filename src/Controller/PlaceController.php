<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Form\CommentType;
use App\Repository\CategoryRepository;
use App\Repository\CommentRepository;
use App\Repository\DestinationRepository;
use App\Repository\PlaceRepository;
use App\Repository\TagRepository;
use App\Security\Voter\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class PlaceController extends AbstractController
{
    #[Route('/places', name: 'app_place_index', methods: ['GET'])]
    public function index(
        Request $request,
        PlaceRepository $placeRepository,
        DestinationRepository $destinationRepository,
        CategoryRepository $categoryRepository,
        TagRepository $tagRepository,
    ): Response {
        $destination = null;
        $category = null;
        $tag = null;

        if ($request->query->get('destination')) {
            $destination = $destinationRepository->findOneBy(['slug' => $request->query->get('destination')]);
        }

        if ($request->query->get('category')) {
            $category = $categoryRepository->findOneBy(['slug' => $request->query->get('category')]);
        }

        if ($request->query->get('tag')) {
            $tag = $tagRepository->findOneBy(['slug' => $request->query->get('tag')]);
        }

        return $this->render('place/index.html.twig', [
            'places' => $placeRepository->findPublished($destination, $category, $tag),
            'destinations' => $destinationRepository->findDiscoverableDestinations(20),
            'categories' => $categoryRepository->findBy([], ['name' => 'ASC']),
            'tags' => $tagRepository->findBy([], ['name' => 'ASC']),
            'current_destination' => $destination,
            'current_category' => $category,
            'current_tag' => $tag,
        ]);
    }

    #[Route('/places/{slug}', name: 'app_place_show', methods: ['GET'])]
    public function show(string $slug, PlaceRepository $placeRepository, CommentRepository $commentRepository): Response
    {
        $place = $placeRepository->findPublishedBySlug($slug);
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
