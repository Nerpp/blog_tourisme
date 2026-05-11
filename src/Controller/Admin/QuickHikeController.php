<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\Place;
use App\Enum\CategoryType;
use App\Enum\ContentStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Repository\CategoryRepository;
use App\Repository\DestinationRepository;
use App\Repository\PlaceRepository;
use App\Security\Voter\QuickHikeVoter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AdminRoute(
    path: '/quick-hike',
    name: 'quick_hike',
    options: ['methods' => ['GET', 'POST']],
    allowedDashboards: [DashboardController::class],
)]
final class QuickHikeController extends AbstractController
{
    private const DEFAULT_NOTE = 'Randonnée créée rapidement depuis le terrain.';

    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly DestinationRepository $destinationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlaceRepository $placeRepository,
        private readonly SluggerInterface $slugger,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->getUser() === null) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isGranted(QuickHikeVoter::CREATE)) {
            $this->addFlash('warning', 'Vous n’avez pas accès à cette page.');

            return $this->redirectToRoute('app_home');
        }

        if (!$request->isMethod('POST')) {
            return $this->renderPage($this->defaultFormData());
        }

        $formData = $this->submittedFormData($request);
        $errors = $this->validateFormData($formData);

        if (!$this->isCsrfTokenValid('quick_hike_create', (string) ($formData['_token'] ?? ''))) {
            $errors[] = 'Le formulaire a expiré. Merci de réessayer.';
        }

        if ($errors !== []) {
            return $this->renderPage($formData, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $destination = $this->destinationRepository->find((int) $formData['destinationId']);
        if (!$destination instanceof Destination) {
            return $this->renderPage(
                $formData,
                ['La destination sélectionnée est introuvable.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $place = (new Place())
            ->setName($formData['title'])
            ->setSlug($this->createUniqueSlug($formData['title']))
            ->setDestination($destination)
            ->setCategory($this->getOrCreateHikeCategory())
            ->setStatus(ContentStatus::Draft)
            ->setLatitude((float) $formData['latitude'])
            ->setLongitude((float) $formData['longitude'])
            ->setShortDescription($formData['note'] !== '' ? $formData['note'] : self::DEFAULT_NOTE)
            ->setDescription('Fiche randonnée créée rapidement depuis le terrain. À compléter.')
            ->setPriceType(PriceType::Free)
            ->setDifficulty(PlaceDifficulty::Unknown);

        $this->entityManager->persist($place);
        $this->entityManager->flush();

        $this->addFlash('success', 'Brouillon de randonnée créé avec la position GPS.');

        return new RedirectResponse($this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(PlaceCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($place->getId())
            ->generateUrl());
    }

    /**
     * @param array<string, mixed> $formData
     * @param list<string>         $errors
     */
    private function renderPage(array $formData, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/quick_hike.html.twig', [
            'destinations' => $this->destinationRepository->findBy([], ['name' => 'ASC']),
            'errors' => $errors,
            'form_data' => $formData,
        ], new Response(status: $status));
    }

    /** @return array<string, string> */
    private function defaultFormData(): array
    {
        return [
            '_token' => '',
            'title' => sprintf('Randonnée du %s', (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H\hi')),
            'latitude' => '',
            'longitude' => '',
            'accuracy' => '',
            'destinationId' => '',
            'note' => '',
        ];
    }

    /** @return array<string, string> */
    private function submittedFormData(Request $request): array
    {
        $data = $request->request->all('quick_hike');

        return [
            '_token' => trim((string) ($data['_token'] ?? '')),
            'title' => trim((string) ($data['title'] ?? '')),
            'latitude' => $this->normalizeDecimal((string) ($data['latitude'] ?? '')),
            'longitude' => $this->normalizeDecimal((string) ($data['longitude'] ?? '')),
            'accuracy' => $this->normalizeDecimal((string) ($data['accuracy'] ?? '')),
            'destinationId' => trim((string) ($data['destinationId'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }

    /**
     * @param array<string, string> $formData
     *
     * @return list<string>
     */
    private function validateFormData(array $formData): array
    {
        $errors = [];

        if ($formData['title'] === '') {
            $errors[] = 'Le titre est obligatoire.';
        }

        if (!$this->isCoordinate($formData['latitude'], -90, 90)) {
            $errors[] = 'La latitude GPS est invalide.';
        }

        if (!$this->isCoordinate($formData['longitude'], -180, 180)) {
            $errors[] = 'La longitude GPS est invalide.';
        }

        if ($formData['destinationId'] === '' || !ctype_digit($formData['destinationId'])) {
            $errors[] = 'La destination est obligatoire.';
        }

        return $errors;
    }

    private function normalizeDecimal(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }

    private function isCoordinate(string $value, float $min, float $max): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        $coordinate = (float) $value;

        return $coordinate >= $min && $coordinate <= $max;
    }

    private function getOrCreateHikeCategory(): Category
    {
        $category = $this->categoryRepository->findOneBy(['slug' => 'randonnee'])
            ?? $this->categoryRepository->findOneBy(['name' => 'Randonnée']);

        if ($category instanceof Category) {
            return $category;
        }

        $category = (new Category())
            ->setName('Randonnée')
            ->setSlug('randonnee')
            ->setType(CategoryType::Place);

        $this->entityManager->persist($category);

        return $category;
    }

    private function createUniqueSlug(string $title): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($title));
        $baseSlug = trim($baseSlug, '-') ?: 'randonnee';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->placeRepository->findOneBy(['slug' => $slug]) instanceof Place) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }
}
