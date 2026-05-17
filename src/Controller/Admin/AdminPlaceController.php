<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\Place;
use App\Enum\ContentStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Repository\CategoryRepository;
use App\Repository\DestinationRepository;
use App\Repository\PlaceRepository;
use App\Security\Voter\AdminAccessVoter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminPlaceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('/admin/places', name: 'admin_places_index', methods: ['GET'])]
    public function index(PlaceRepository $placeRepository): Response
    {
        return $this->render('admin/places/index.html.twig', [
            'places' => $placeRepository->findBy([], ['updatedAt' => 'DESC', 'createdAt' => 'DESC'], 100),
            'status_labels' => $this->statusLabels(),
            'difficulty_labels' => $this->difficultyLabels(),
            'price_labels' => $this->priceLabels(),
        ]);
    }

    #[Route('/admin/places/new', name: 'admin_places_new', methods: ['GET', 'POST'])]
    public function new(Request $request, DestinationRepository $destinationRepository, CategoryRepository $categoryRepository): Response
    {
        $place = new Place();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_place_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updatePlaceFromRequest($place, $request)) {
                $place->setSlug($this->createUniqueSlug($place->getName() ?? 'reperage'));
                $this->entityManager->persist($place);
                $this->entityManager->flush();
                $this->addFlash('success', 'Repérage créé.');

                return $this->redirectToRoute('admin_places_index');
            }
        }

        return $this->renderPlaceForm($place, $destinationRepository, $categoryRepository, 'Nouveau repérage', 'Créer le repérage');
    }

    #[Route('/admin/places/{id}/edit', name: 'admin_places_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Place $place, Request $request, DestinationRepository $destinationRepository, CategoryRepository $categoryRepository): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_place_'.$place->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updatePlaceFromRequest($place, $request)) {
                $this->entityManager->flush();
                $this->addFlash('success', 'Repérage enregistré.');

                return $this->redirectToRoute('admin_places_index');
            }
        }

        return $this->renderPlaceForm($place, $destinationRepository, $categoryRepository, 'Modifier le repérage', 'Enregistrer');
    }

    #[Route('/admin/places/{id}/delete', name: 'admin_places_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Place $place, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_place_delete_'.$place->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $place->setStatus(ContentStatus::Archived);
        $this->entityManager->flush();
        $this->addFlash('success', 'Repérage archivé.');

        return $this->redirectToRoute('admin_places_index');
    }

    private function renderPlaceForm(
        Place $place,
        DestinationRepository $destinationRepository,
        CategoryRepository $categoryRepository,
        string $title,
        string $submitLabel,
    ): Response {
        return $this->render('admin/places/form.html.twig', [
            'place' => $place,
            'destinations' => $destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']),
            'categories' => $categoryRepository->findBy([], ['name' => 'ASC']),
            'status_options' => $this->statusLabels(),
            'difficulty_options' => $this->difficultyLabels(),
            'price_options' => $this->priceLabels(),
            'title' => $title,
            'submit_label' => $submitLabel,
        ]);
    }

    private function updatePlaceFromRequest(Place $place, Request $request): bool
    {
        $name = trim($request->request->getString('name'));
        $destinationId = $this->nullableInt($request->request->get('destination'));
        $destination = $destinationId !== null ? $this->entityManager->find(Destination::class, $destinationId) : null;
        if ($name === '' || !$destination instanceof Destination) {
            $this->addFlash('error', 'Le nom et la destination sont obligatoires.');

            return false;
        }

        $status = ContentStatus::tryFrom($request->request->getString('status')) ?? ContentStatus::Draft;
        $place
            ->setName($name)
            ->setDestination($destination)
            ->setCategory(($categoryId = $this->nullableInt($request->request->get('category'))) !== null ? $this->entityManager->find(Category::class, $categoryId) : null)
            ->setStatus($status)
            ->setShortDescription($this->nullIfBlank($request->request->getString('shortDescription')))
            ->setLatitude($this->nullableFloat($request->request->get('latitude')))
            ->setLongitude($this->nullableFloat($request->request->get('longitude')))
            ->setVisitDurationMinutes($this->nullableInt($request->request->get('visitDurationMinutes')))
            ->setDifficulty(PlaceDifficulty::tryFrom($request->request->getString('difficulty')) ?? PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::tryFrom($request->request->getString('priceType')) ?? PriceType::Unknown);

        if ($status === ContentStatus::Published && $place->getPublishedAt() === null) {
            $place->setPublishedAt(new DateTimeImmutable());
        }

        return true;
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            ContentStatus::Draft->value => 'Brouillon',
            ContentStatus::Published->value => 'Publié',
            ContentStatus::PrivateContent->value => 'Privé',
            ContentStatus::Archived->value => 'Archivé',
        ];
    }

    /** @return array<string, string> */
    private function difficultyLabels(): array
    {
        return [
            PlaceDifficulty::Easy->value => 'Facile',
            PlaceDifficulty::Medium->value => 'Moyen',
            PlaceDifficulty::Hard->value => 'Difficile',
            PlaceDifficulty::Unknown->value => 'Non renseignée',
        ];
    }

    /** @return array<string, string> */
    private function priceLabels(): array
    {
        return [
            PriceType::Free->value => 'Gratuit',
            PriceType::Paid->value => 'Payant',
            PriceType::Mixed->value => 'Mixte',
            PriceType::Unknown->value => 'Non renseigné',
        ];
    }

    private function createUniqueSlug(string $name): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($name));
        $baseSlug = trim($baseSlug, '-') ?: 'reperage';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->entityManager->getRepository(Place::class)->findOneBy(['slug' => $slug]) !== null) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
