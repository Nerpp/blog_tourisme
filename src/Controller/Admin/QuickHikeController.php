<?php

namespace App\Controller\Admin;

use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Entity\User;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\QuickHikeVoter;
use App\Service\PublicationNotificationMailer;
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

#[Route('/admin/quick-hike', name: 'admin_quick_hike_')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class QuickHikeController extends AbstractController
{
    public function __construct(
        private readonly HikeDraftRepository $hikeDraftRepository,
        private readonly DestinationRepository $destinationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TerrainLocationResolver $terrainLocationResolver,
        private readonly SluggerInterface $slugger,
        private readonly PublicationNotificationMailer $publicationNotificationMailer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        $user = $this->getUser();
        if ($user instanceof User && ($draft = $this->hikeDraftRepository->findCurrentDraftForUser($user)) instanceof HikeDraft) {
            return $this->redirectToRoute('admin_quick_hike_show', ['id' => $draft->getId()]);
        }

        return $this->render('admin/quick_hike/index.html.twig', [
            'default_title' => $this->defaultHikeTitle(),
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']),
            'destination_quick_create' => $this->emptyDestinationQuickCreateData(),
        ]);
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('quick_hike_start', (string) $request->request->get('_token', ''))) {
            $this->addFlash('warning', 'Le formulaire a expiré. Merci de réessayer.');

            return $this->redirectToRoute('admin_quick_hike_index');
        }

        $title = trim((string) $request->request->get('title', '')) ?: $this->defaultHikeTitle();
        $notes = trim((string) $request->request->get('notes', ''));
        $draft = (new HikeDraft())
            ->setTitle($title)
            ->setSlug($this->createUniqueSlug($title))
            ->setStatus(HikeDraftStatus::Draft)
            ->setNotes($notes !== '' ? $notes : null);

        $user = $this->getUser();
        if ($user instanceof User) {
            $draft->setCreatedBy($user);
        }

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $this->redirectToRoute('admin_quick_hike_show', ['id' => $draft->getId()]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(HikeDraft $hikeDraft): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        return $this->renderShow($hikeDraft);
    }

    #[Route('/{id}/point', name: 'point', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function point(Request $request, HikeDraft $hikeDraft): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if ($hikeDraft->getStatus() !== HikeDraftStatus::Draft) {
            return $this->pointError($request, 'Cette randonnée n’est plus en brouillon.', $hikeDraft);
        }

        $formData = $this->submittedPointFormData($request);
        $errors = $this->validatePointCoordinates($formData);

        if (!$this->isCsrfTokenValid(sprintf('quick_hike_point_%d', $hikeDraft->getId()), $formData['_token'])) {
            $errors[] = 'Le formulaire a expiré. Merci de réessayer.';
        }

        if ($errors !== []) {
            return $this->pointError($request, $errors[0], $hikeDraft, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pointType = HikePointType::tryFrom($formData['type']) ?? HikePointType::Interest;
        if (!$this->hasStartPoint($hikeDraft)) {
            $pointType = HikePointType::Start;
        }

        $location = $this->terrainLocationResolver->resolve((float) $formData['latitude'], (float) $formData['longitude']);
        $this->applyDraftLocation($hikeDraft, $location['geocoding'], $location['destination']);

        $point = $this->createPoint(
            $hikeDraft,
            $formData,
            $pointType,
            $this->nextPointPosition($hikeDraft),
            $location['geocoding'],
        );

        $hikeDraft->addPoint($point);
        $this->entityManager->flush();

        return $this->pointSuccess($request, 'Point GPS enregistré.', $hikeDraft);
    }

    #[Route('/{id}/finish', name: 'finish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function finish(Request $request, HikeDraft $hikeDraft): Response
    {
        if ($redirect = $this->denyUnlessAdmin()) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid(sprintf('quick_hike_finish_%d', $hikeDraft->getId()), (string) $request->request->get('_token', ''))) {
            $this->addFlash('warning', 'Le formulaire a expiré. Merci de réessayer.');

            return $this->redirectToRoute('admin_quick_hike_show', ['id' => $hikeDraft->getId()]);
        }

        if (!$this->hasStartPoint($hikeDraft)) {
            $this->addFlash('warning', 'Enregistrez le point de départ avant de terminer.');

            return $this->redirectToRoute('admin_quick_hike_show', ['id' => $hikeDraft->getId()]);
        }

        $hadFinishedAt = $hikeDraft->getFinishedAt() !== null;
        $hikeDraft
            ->setStatus(HikeDraftStatus::Finished)
            ->setFinishedAt($hikeDraft->getFinishedAt() ?? new DateTimeImmutable());

        $this->entityManager->flush();
        $this->notifyNewPublication($hikeDraft, !$hadFinishedAt && $hikeDraft->getFinishedAt() !== null);
        $this->addFlash('success', 'Randonnée rapide terminée.');

        return $this->redirectToRoute('admin_quick_hike_show', ['id' => $hikeDraft->getId()]);
    }

    private function notifyNewPublication(HikeDraft $hikeDraft, bool $shouldNotify): void
    {
        if (!$shouldNotify) {
            return;
        }

        $report = $this->publicationNotificationMailer->sendNewPublicationNotification($hikeDraft);
        if ($report['errorCount'] > 0) {
            $this->addFlash('warning', 'La publication a été enregistrée, mais l’envoi des notifications a rencontré une erreur.');
        }
    }

    private function denyUnlessAdmin(): ?RedirectResponse
    {
        if ($this->getUser() === null) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isGranted(QuickHikeVoter::CREATE)) {
            $this->addFlash('warning', 'Vous n’avez pas accès à cet outil terrain.');

            return $this->redirectToRoute('app_home');
        }

        return null;
    }

    /** @param list<string> $errors */
    private function renderShow(HikeDraft $hikeDraft, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/quick_hike/show.html.twig', [
            'hike' => $hikeDraft,
            'errors' => $errors,
            'has_start_point' => $this->hasStartPoint($hikeDraft),
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']),
            'destination_quick_create' => $this->destinationQuickCreateData($hikeDraft),
            'interest_point_types' => $this->pointTypeChoices([
                HikePointType::Interest,
                HikePointType::Viewpoint,
                HikePointType::Photo,
                HikePointType::Water,
                HikePointType::Danger,
                HikePointType::Rest,
                HikePointType::End,
                HikePointType::Other,
            ]),
            'admin_back_url' => $this->generateUrl('admin_field_tools_index'),
        ], new Response(status: $status));
    }

    /** @return array<string, string> */
    private function submittedPointFormData(Request $request): array
    {
        $data = $request->request->all('quick_hike_point');

        return [
            '_token' => trim((string) ($data['_token'] ?? '')),
            'type' => trim((string) ($data['type'] ?? HikePointType::Interest->value)),
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
    private function createPoint(HikeDraft $draft, array $formData, HikePointType $type, int $position, ?array $geocoding): HikePoint
    {
        $point = (new HikePoint())
            ->setHikeDraft($draft)
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
    private function applyDraftLocation(HikeDraft $draft, ?array $geocoding, ?Destination $destination): void
    {
        if (null !== $geocoding && null === $draft->getDetectedCommuneName()) {
            $draft
                ->setDetectedCommuneName($geocoding['communeName'])
                ->setDetectedCommuneCode($geocoding['communeCode'])
                ->setDetectedDepartmentName($geocoding['departmentName'])
                ->setDetectedRegionName($geocoding['regionName']);
        }

        if (null === $draft->getDestination() && $destination instanceof Destination) {
            $draft->setDestination($destination);
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

    /** @return array<string, float|int|string|null> */
    private function emptyDestinationQuickCreateData(): array
    {
        return [
            'contextType' => '',
            'contextId' => null,
            'targetType' => '',
            'targetId' => null,
            'name' => '',
            'countryName' => '',
            'regionName' => '',
            'departmentName' => '',
            'cityName' => '',
            'parent' => null,
            'type' => DestinationType::Area->value,
            'code' => '',
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function destinationQuickCreateData(HikeDraft $draft): array
    {
        $point = $this->latestPoint($draft);
        $cityName = $draft->getDetectedCommuneName() ?? '';

        return [
            'contextType' => 'hike',
            'contextId' => $draft->getId(),
            'targetType' => 'hike',
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

    private function latestPoint(HikeDraft $draft): ?HikePoint
    {
        $point = null;
        foreach ($draft->getPoints() as $candidate) {
            if (!$point instanceof HikePoint || $candidate->getPosition() >= $point->getPosition()) {
                $point = $candidate;
            }
        }

        return $point;
    }

    private function hasStartPoint(HikeDraft $draft): bool
    {
        foreach ($draft->getPoints() as $point) {
            if ($point->getType() === HikePointType::Start) {
                return true;
            }
        }

        return false;
    }

    private function nextPointPosition(HikeDraft $draft): int
    {
        $position = 0;
        foreach ($draft->getPoints() as $point) {
            $position = max($position, $point->getPosition());
        }

        return $position + 1;
    }

    private function pointSuccess(Request $request, string $message, HikeDraft $draft): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => true,
                'message' => $message,
                'redirect' => $this->generateUrl('admin_quick_hike_show', ['id' => $draft->getId()]),
            ]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_quick_hike_show', ['id' => $draft->getId()]);
    }

    private function pointError(Request $request, string $message, HikeDraft $draft, int $status = Response::HTTP_BAD_REQUEST): Response
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
     * @param list<HikePointType> $types
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

    private function pointTypeLabel(HikePointType $type): string
    {
        return match ($type) {
            HikePointType::Start => 'Départ',
            HikePointType::Interest => 'Point d’intérêt',
            HikePointType::Viewpoint => 'Point de vue',
            HikePointType::Photo => 'Photo',
            HikePointType::Water => 'Eau',
            HikePointType::Danger => 'Danger',
            HikePointType::Rest => 'Pause',
            HikePointType::End => 'Arrivée',
            HikePointType::Other => 'Autre',
        };
    }

    private function defaultHikeTitle(): string
    {
        return sprintf('Randonnée du %s', (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y à H\hi'));
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
        $baseSlug = trim($baseSlug, '-') ?: 'randonnee-rapide';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->hikeDraftRepository->findOneBy(['slug' => $slug]) instanceof HikeDraft) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }
}
