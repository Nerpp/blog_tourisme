<?php

namespace App\Controller\Admin;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\Destination;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\QuickCityVisitVoter;
use App\Service\GeographicHierarchyResolver;
use App\Service\TerrainLocationResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/quick-city-visit', name: 'admin_quick_city_visit_')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class QuickCityVisitController extends AbstractController
{
    private const PREPARED_DESTINATION_SESSION_KEY = 'quick_city_visit_destination_id';
    private const PREPARED_DESTINATION_POSTAL_CODE_SESSION_KEY = 'quick_city_visit_destination_postal_code';

    public function __construct(
        private readonly CityVisitDraftRepository $cityVisitDraftRepository,
        private readonly DestinationRepository $destinationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TerrainLocationResolver $terrainLocationResolver,
        private readonly GeographicHierarchyResolver $geographicHierarchyResolver,
        private readonly SluggerInterface $slugger,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): RedirectResponse
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        $mode = $request->query->getString('mode');

        return $this->redirectToRoute('admin_quick_index', [
            'type' => 'city_visit',
            'mode' => in_array($mode, ['terrain', 'distance'], true) ? $mode : 'choice',
        ]);
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('quick_city_visit_start', (string) $request->request->get('_token', ''))) {
            $this->addFlash('warning', 'Le formulaire a expiré. Merci de réessayer.');

            return $this->redirectToRoute('admin_quick_city_visit_index');
        }

        $title = trim((string) $request->request->get('title', '')) ?: $this->defaultVisitTitle();
        $notes = trim((string) $request->request->get('notes', ''));
        $draft = (new CityVisitDraft())
            ->setTitle($title)
            ->setSlug($this->createUniqueSlug($title))
            ->setStatus(CityVisitDraftStatus::Draft)
            ->setNotes($notes !== '' ? $notes : null);

        $preparedDestination = $this->preparedDestination($request);
        if ($preparedDestination instanceof Destination) {
            $draft->setDestination($preparedDestination);
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $draft->setCreatedBy($user);
        }

        $this->entityManager->persist($draft);
        $this->entityManager->flush();
        $this->clearPreparedDestination($request);

        if ($preparedDestination instanceof Destination) {
            return $this->redirectToRoute('admin_studio_city_visit_edit', ['id' => $draft->getId()]);
        }

        return $this->redirectToRoute('admin_quick_city_visit_show', ['id' => $draft->getId()]);
    }

    #[Route('/destination/clear', name: 'clear_destination', methods: ['POST'])]
    public function clearDestination(Request $request): RedirectResponse
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('quick_city_visit_clear_destination', (string) $request->request->get('_token', ''))) {
            $this->addFlash('warning', 'Le formulaire a expiré. Merci de réessayer.');

            return $this->redirectToRoute('admin_quick_city_visit_index');
        }

        $this->clearPreparedDestination($request);
        $this->addFlash('success', 'La destination préparée a été retirée.');

        return $this->redirectToRoute('admin_quick_city_visit_index');
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(CityVisitDraft $cityVisitDraft): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        return $this->renderShow($cityVisitDraft);
    }

    #[Route('/{id}/point', name: 'point', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function point(Request $request, CityVisitDraft $cityVisitDraft): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if ($cityVisitDraft->getStatus() !== CityVisitDraftStatus::Draft || $cityVisitDraft->getFinishedAt() !== null) {
            return $this->pointError($request, 'Cette sortie terrain est terminée. Modifiez la visite depuis le studio.', $cityVisitDraft);
        }

        $formData = $this->submittedPointFormData($request);
        $errors = $this->validatePointCoordinates($formData);

        if (!$this->isCsrfTokenValid(sprintf('quick_city_visit_point_%d', $cityVisitDraft->getId()), $formData['_token'])) {
            $errors[] = 'Le formulaire a expiré. Merci de réessayer.';
        }

        if ($errors !== []) {
            return $this->pointError($request, $errors[0], $cityVisitDraft, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pointType = CityVisitPointType::tryFrom($formData['type']) ?? CityVisitPointType::Other;
        $location = $this->terrainLocationResolver->resolve((float) $formData['latitude'], (float) $formData['longitude']);
        $this->applyDraftLocation($cityVisitDraft, $location['geocoding']);

        $point = $this->createPoint(
            $cityVisitDraft,
            $formData,
            $pointType,
            $this->nextPointPosition($cityVisitDraft),
            $location['geocoding'],
        );

        $cityVisitDraft->addPoint($point);
        $cityVisitDraft->setGoogleMapsUrl($this->generateGoogleMapsUrl($cityVisitDraft));

        $this->entityManager->flush();

        return $this->pointSuccess($request, 'Point GPS enregistré.', $cityVisitDraft);
    }

    #[Route('/{id}/finish', name: 'finish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function finish(Request $request, CityVisitDraft $cityVisitDraft): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid(sprintf('quick_city_visit_finish_%d', $cityVisitDraft->getId()), (string) $request->request->get('_token', ''))) {
            $this->addFlash('warning', 'Le formulaire a expiré. Merci de réessayer.');

            return $this->redirectToRoute('admin_quick_city_visit_show', ['id' => $cityVisitDraft->getId()]);
        }

        $cityVisitDraft
            ->setStatus(CityVisitDraftStatus::Draft)
            ->setFinishedAt($cityVisitDraft->getFinishedAt() ?? new DateTimeImmutable())
            ->setGoogleMapsUrl($this->generateGoogleMapsUrl($cityVisitDraft));

        $this->entityManager->flush();

        $this->addFlash('success', 'Sortie terrain terminée. La visite reste en brouillon pour ajouter les photos et préparer la publication.');

        return $this->redirectToRoute('admin_studio_city_visit_edit', [
            'id' => $cityVisitDraft->getId(),
        ]);
    }



    private function denyUnlessAdmin(): ?RedirectResponse
    {
        if ($this->getUser() === null) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isGranted(QuickCityVisitVoter::CREATE)) {
            $this->addFlash('warning', 'Vous n’avez pas accès à cet outil terrain.');

            return $this->redirectToRoute('app_home');
        }

        return null;
    }

    /** @param list<string> $errors */
    private function renderShow(CityVisitDraft $cityVisitDraft, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/quick_city_visit/show.html.twig', [
            'city_visit' => $cityVisitDraft,
            'errors' => $errors,
            'point_types' => $this->pointTypeChoices(CityVisitPointType::cases()),
            'point_count' => $cityVisitDraft->getPoints()->count(),
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']),
            'destination_quick_create' => $this->destinationQuickCreateData($cityVisitDraft),
            'admin_back_url' => $this->generateUrl('admin_quick_index'),
        ], new Response(status: $status));
    }

    /** @return array<string, string> */
    private function submittedPointFormData(Request $request): array
    {
        $data = $request->request->all('quick_city_visit_point');

        return [
            '_token' => trim((string) ($data['_token'] ?? '')),
            'type' => trim((string) ($data['type'] ?? CityVisitPointType::Other->value)),
            'titlePoint' => trim((string) ($data['titlePoint'] ?? '')),
            'latitude' => $this->normalizeDecimal((string) ($data['latitude'] ?? '')),
            'longitude' => $this->normalizeDecimal((string) ($data['longitude'] ?? '')),
            'accuracy' => $this->normalizeDecimal((string) ($data['accuracy'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }

    /**
     * @param array<string, string> $formData
     *
     * @return list<string>
     */
    private function validatePointCoordinates(array $formData): array
    {
        $errors = [];

        if (!$this->isCoordinate($formData['latitude'], -90, 90)) {
            $errors[] = 'La position GPS est obligatoire.';
        }

        if (!$this->isCoordinate($formData['longitude'], -180, 180)) {
            $errors[] = 'La longitude GPS est invalide.';
        }

        if ($formData['accuracy'] !== '' && !is_numeric($formData['accuracy'])) {
            $errors[] = 'La précision GPS est invalide.';
        }

        return $errors;
    }

    /**
     * @param array<string, string>      $formData
     * @param array<string, string>|null $geocoding
     */
    private function createPoint(CityVisitDraft $draft, array $formData, CityVisitPointType $type, int $position, ?array $geocoding): CityVisitPoint
    {
        $point = (new CityVisitPoint())
            ->setCityVisitDraft($draft)
            ->setType($type)
            ->setTitle($formData['titlePoint'] !== '' ? $formData['titlePoint'] : null)
            ->setNote($formData['note'] !== '' ? $formData['note'] : null)
            ->setLatitude((float) $formData['latitude'])
            ->setLongitude((float) $formData['longitude'])
            ->setAccuracy($formData['accuracy'] !== '' ? (float) $formData['accuracy'] : null)
            ->setPosition($position);

        if (null !== $geocoding) {
            $point
                ->setDetectedCommuneName($geocoding['communeName'])
                ->setDetectedCommuneCode($geocoding['communeCode'])
                ->setDetectedDepartmentName($geocoding['departmentName'])
                ->setDetectedRegionName($geocoding['regionName']);
        }

        return $point;
    }

    /** @param array<string, string>|null $geocoding */
    private function applyDraftLocation(CityVisitDraft $draft, ?array $geocoding): void
    {
        if (null !== $geocoding && null === $draft->getDetectedCommuneName()) {
            $draft
                ->setDetectedCommuneName($geocoding['communeName'])
                ->setDetectedCommuneCode($geocoding['communeCode'])
                ->setDetectedDepartmentName($geocoding['departmentName'])
                ->setDetectedRegionName($geocoding['regionName']);
        }

        if (null !== $geocoding && null === $draft->getGeographicDestination()) {
            $draft->setGeographicDestination($this->geographicHierarchyResolver->resolveCommune(
                $geocoding['communeName'],
                $geocoding['communeCode'],
                $geocoding['departmentName'],
                $geocoding['regionName'],
                departmentCode: $geocoding['departmentCode'] ?? null,
                regionCode: $geocoding['regionCode'] ?? null,
            ));
        }
    }

    /** @return array<string, string> */
    private function destinationTypeOptions(): array
    {
        return [
            DestinationType::Country->value => 'Pays',
            DestinationType::Region->value => 'Région',
            DestinationType::Department->value => 'Département / province',
            DestinationType::City->value => 'Ville',
            DestinationType::Area->value => 'Zone / lieu',
        ];
    }

    private function preparedDestination(Request $request): ?Destination
    {
        $destinationId = $this->nullableInt($request->getSession()->get(self::PREPARED_DESTINATION_SESSION_KEY));

        if ($destinationId === null) {
            return null;
        }

        $destination = $this->destinationRepository->find($destinationId);
        if (!$destination instanceof Destination) {
            $this->clearPreparedDestination($request);

            return null;
        }

        return $destination;
    }

    private function clearPreparedDestination(Request $request): void
    {
        $session = $request->getSession();
        $session->remove(self::PREPARED_DESTINATION_SESSION_KEY);
        $session->remove(self::PREPARED_DESTINATION_POSTAL_CODE_SESSION_KEY);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    /** @return array<string, float|int|string|null> */
    private function destinationQuickCreateData(CityVisitDraft $draft): array
    {
        $point = $this->latestPoint($draft);
        $cityName = $draft->getDetectedCommuneName() ?? '';

        return [
            'contextType' => 'city_visit',
            'contextId' => $draft->getId(),
            'targetType' => 'city_visit',
            'targetId' => $draft->getId(),
            'name' => $cityName,
            'countryName' => $cityName !== '' ? 'France' : '',
            'regionName' => $draft->getDetectedRegionName() ?? '',
            'departmentName' => $draft->getDetectedDepartmentName() ?? '',
            'cityName' => $cityName,
            'parent' => null,
            'type' => $cityName !== '' ? DestinationType::City->value : DestinationType::Area->value,
            'code' => $draft->getDetectedCommuneCode() ?? '',
            'latitude' => $point?->getLatitude(),
            'longitude' => $point?->getLongitude(),
        ];
    }

    private function latestPoint(CityVisitDraft $draft): ?CityVisitPoint
    {
        $point = null;
        foreach ($draft->getPoints() as $candidate) {
            if (!$point instanceof CityVisitPoint || $candidate->getPosition() >= $point->getPosition()) {
                $point = $candidate;
            }
        }

        return $point;
    }

    private function nextPointPosition(CityVisitDraft $draft): int
    {
        $position = 0;
        foreach ($draft->getPoints() as $point) {
            $position = max($position, $point->getPosition());
        }

        return $position + 1;
    }

    private function generateGoogleMapsUrl(CityVisitDraft $draft): ?string
    {
        $points = $draft->getPoints()->toArray();
        usort($points, static fn(CityVisitPoint $a, CityVisitPoint $b): int => $a->getPosition() <=> $b->getPosition());

        if (\count($points) < 2) {
            return null;
        }

        $origin = $points[0];
        $destination = $points[\count($points) - 1];
        $waypoints = [];

        foreach (\array_slice($points, 1, -1) as $point) {
            $waypoints[] = $this->formatCoordinates($point);
        }

        $url = sprintf(
            'https://www.google.com/maps/dir/?api=1&origin=%s&destination=%s',
            $this->formatCoordinates($origin),
            $this->formatCoordinates($destination),
        );

        if ($waypoints !== []) {
            $url .= '&waypoints=' . implode('|', $waypoints);
        }

        return $url . '&travelmode=walking';
    }

    private function formatCoordinates(CityVisitPoint $point): string
    {
        return sprintf('%.6F,%.6F', $point->getLatitude(), $point->getLongitude());
    }

    private function pointSuccess(Request $request, string $message, CityVisitDraft $draft): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => true,
                'message' => $message,
                'redirect' => $this->generateUrl('admin_quick_city_visit_show', ['id' => $draft->getId()]),
            ]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_quick_city_visit_show', ['id' => $draft->getId()]);
    }

    private function pointError(Request $request, string $message, CityVisitDraft $draft, int $status = Response::HTTP_BAD_REQUEST): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => false,
                'message' => $message,
            ], $status);
        }

        return $this->renderShow($draft, [$message], $status);
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json')
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * @param list<CityVisitPointType> $types
     *
     * @return array<string, string>
     */
    private function pointTypeChoices(array $types): array
    {
        $choices = [];
        foreach ($types as $type) {
            $choices[$type->value] = $this->pointTypeLabel($type);
        }

        return $choices;
    }

    private function pointTypeLabel(CityVisitPointType $type): string
    {
        return match ($type) {
            CityVisitPointType::Start => 'Départ',
            CityVisitPointType::Monument => 'Monument',
            CityVisitPointType::Viewpoint => 'Point de vue',
            CityVisitPointType::Museum => 'Musée',
            CityVisitPointType::Church => 'Église',
            CityVisitPointType::Square => 'Place',
            CityVisitPointType::Restaurant => 'Restaurant',
            CityVisitPointType::Photo => 'Photo',
            CityVisitPointType::Parking => 'Parking',
            CityVisitPointType::End => 'Arrivée',
            CityVisitPointType::Other => 'Autre',
        };
    }

    private function defaultVisitTitle(): string
    {
        return sprintf('Visite de ville du %s', (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H\hi'));
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

    private function createUniqueSlug(string $title): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($title));
        $baseSlug = trim($baseSlug, '-') ?: 'visite-ville';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->cityVisitDraftRepository->findOneBy(['slug' => $slug]) instanceof CityVisitDraft) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }
}
