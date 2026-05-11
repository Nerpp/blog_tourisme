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
use App\Service\ReverseGeocodingService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly ReverseGeocodingService $reverseGeocodingService,
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

        $destination = $this->resolveDestination($formData);
        if (!$destination instanceof Destination) {
            return $this->renderPage(
                $formData,
                ['La destination est obligatoire si la commune détectée ne correspond à aucune destination existante.'],
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

    #[Route('/admin/quick-hike/reverse-geocode', name: 'admin_quick_hike_reverse_geocode', methods: ['GET'])]
    public function reverseGeocode(Request $request): Response
    {
        if ($this->getUser() === null) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isGranted(QuickHikeVoter::CREATE)) {
            $this->addFlash('warning', 'Vous n’avez pas accès à cette page.');

            return $this->redirectToRoute('app_home');
        }

        $latitude = $this->normalizeDecimal((string) $request->query->get('lat', ''));
        $longitude = $this->normalizeDecimal((string) $request->query->get('lon', ''));

        if (!$this->isCoordinate($latitude, -90, 90) || !$this->isCoordinate($longitude, -180, 180)) {
            return new JsonResponse([
                'found' => false,
                'message' => 'Coordonnées GPS invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $geocoding = $this->reverseGeocodingService->reverse((float) $latitude, (float) $longitude);
        if (null === $geocoding) {
            return new JsonResponse([
                'found' => false,
                'message' => 'Commune non détectée. Vous pouvez choisir une destination manuellement.',
            ]);
        }

        $destination = $this->findDestinationForCommune($geocoding['communeCode'], $geocoding['communeName']);

        return new JsonResponse([
            'found' => true,
            'communeName' => $geocoding['communeName'],
            'communeCode' => $geocoding['communeCode'],
            'departmentName' => $geocoding['departmentName'],
            'departmentCode' => $geocoding['departmentCode'],
            'regionName' => $geocoding['regionName'],
            'regionCode' => $geocoding['regionCode'],
            'destination' => $destination instanceof Destination ? [
                'id' => $destination->getId(),
                'name' => $destination->getName(),
            ] : null,
        ]);
    }

    /**
     * @param array<string, mixed> $formData
     * @param list<string>         $errors
     */
    private function renderPage(array $formData, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        if (($formData['destinationId'] ?? '') === '' && ($destination = $this->destinationFromDetectedCommune($formData)) instanceof Destination) {
            $formData['destinationId'] = (string) $destination->getId();
        }

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
            'detectedCommuneName' => '',
            'detectedCommuneCode' => '',
            'detectedDepartmentName' => '',
            'detectedDepartmentCode' => '',
            'detectedRegionName' => '',
            'detectedRegionCode' => '',
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
            'detectedCommuneName' => trim((string) ($data['detectedCommuneName'] ?? '')),
            'detectedCommuneCode' => trim((string) ($data['detectedCommuneCode'] ?? '')),
            'detectedDepartmentName' => trim((string) ($data['detectedDepartmentName'] ?? '')),
            'detectedDepartmentCode' => trim((string) ($data['detectedDepartmentCode'] ?? '')),
            'detectedRegionName' => trim((string) ($data['detectedRegionName'] ?? '')),
            'detectedRegionCode' => trim((string) ($data['detectedRegionCode'] ?? '')),
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

        return $errors;
    }

    /** @param array<string, string> $formData */
    private function resolveDestination(array $formData): ?Destination
    {
        $detectedDestination = $this->destinationFromDetectedCommune($formData);
        if ($detectedDestination instanceof Destination) {
            return $detectedDestination;
        }

        if ($formData['destinationId'] === '' || !ctype_digit($formData['destinationId'])) {
            return null;
        }

        return $this->destinationRepository->find((int) $formData['destinationId']);
    }

    /** @param array<string, string> $formData */
    private function destinationFromDetectedCommune(array $formData): ?Destination
    {
        if (($formData['detectedCommuneCode'] ?? '') === '' && ($formData['detectedCommuneName'] ?? '') === '') {
            return null;
        }

        return $this->findDestinationForCommune(
            $formData['detectedCommuneCode'] ?? '',
            $formData['detectedCommuneName'] ?? '',
        );
    }

    private function findDestinationForCommune(string $communeCode, string $communeName): ?Destination
    {
        if ('' !== $communeCode) {
            $destination = $this->destinationRepository->findOneBy(['code' => $communeCode]);
            if ($destination instanceof Destination) {
                return $destination;
            }
        }

        if ('' === $communeName) {
            return null;
        }

        $slug = strtolower((string) $this->slugger->slug($communeName));

        return $this->destinationRepository->findOneBy(['slug' => $slug])
            ?? $this->destinationRepository->findOneBy(['name' => $communeName]);
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
