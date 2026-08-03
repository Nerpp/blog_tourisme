<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitPoint;
use App\Entity\HikeDraft;
use App\Entity\HikePoint;
use App\Enum\CityVisitPointType;
use App\Enum\HikePointType;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\RouteStep\RouteStepOrderingException;
use App\Service\RouteStep\RouteStepOrderingService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/studio')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class RouteStepController extends AbstractController
{
    public function __construct(
        private readonly RouteStepOrderingService $orderingService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/hikes/{id}/points/add', name: 'admin_studio_hike_point_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addHikePoint(HikeDraft $hikeDraft, Request $request): RedirectResponse
    {
        return $this->addPoint($hikeDraft, $request);
    }

    #[Route('/city-visits/{id}/points/add', name: 'admin_studio_city_visit_point_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addCityVisitPoint(CityVisitDraft $cityVisitDraft, Request $request): RedirectResponse
    {
        return $this->addPoint($cityVisitDraft, $request);
    }

    #[Route('/hike-points/{id}/update', name: 'admin_studio_hike_point_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateHikePoint(HikePoint $hikePoint, Request $request): RedirectResponse
    {
        $draft = $hikePoint->getHikeDraft();
        if (!$draft instanceof HikeDraft) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        return $this->updatePoint($draft, $hikePoint, $request);
    }

    #[Route('/city-visit-points/{id}/update', name: 'admin_studio_city_visit_point_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateCityVisitPoint(CityVisitPoint $cityVisitPoint, Request $request): RedirectResponse
    {
        $draft = $cityVisitPoint->getCityVisitDraft();
        if (!$draft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Visite introuvable.');
        }

        return $this->updatePoint($draft, $cityVisitPoint, $request);
    }

    #[Route('/hikes/{id}/points/reorder', name: 'admin_studio_hike_points_reorder', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reorderHikePoints(HikeDraft $hikeDraft, Request $request): JsonResponse
    {
        return $this->reorderPoints($hikeDraft, $request);
    }

    #[Route('/city-visits/{id}/points/reorder', name: 'admin_studio_city_visit_points_reorder', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reorderCityVisitPoints(CityVisitDraft $cityVisitDraft, Request $request): JsonResponse
    {
        return $this->reorderPoints($cityVisitDraft, $request);
    }

    #[Route('/hike-points/{id}/delete', name: 'admin_studio_hike_point_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteHikePoint(HikePoint $hikePoint, Request $request): RedirectResponse
    {
        $draft = $hikePoint->getHikeDraft();
        if (!$draft instanceof HikeDraft) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        return $this->deletePoint($draft, $hikePoint, $request);
    }

    #[Route('/city-visit-points/{id}/delete', name: 'admin_studio_city_visit_point_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteCityVisitPoint(CityVisitPoint $cityVisitPoint, Request $request): RedirectResponse
    {
        $draft = $cityVisitPoint->getCityVisitDraft();
        if (!$draft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Visite introuvable.');
        }

        return $this->deletePoint($draft, $cityVisitPoint, $request);
    }

    private function addPoint(HikeDraft|CityVisitDraft $draft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $draft);
        $tokenId = $this->tokenId($draft, 'add');
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Le formulaire d’ajout d’étape a expiré. Réessayez.');

            return $this->studioRedirect($draft, 'section-points');
        }

        $position = $this->positiveInteger($request->request->all()['position'] ?? null);
        if ($position === null) {
            $this->addFlash('error', 'L’emplacement demandé pour la nouvelle étape est invalide.');

            return $this->studioRedirect($draft, 'section-points');
        }

        try {
            /** @var HikePoint|CityVisitPoint $point */
            $point = $this->entityManager->wrapInTransaction(function () use ($draft, $position): HikePoint|CityVisitPoint {
                $this->lockDraft($draft);
                $steps = $this->orderingService->orderedSteps($draft);
                if ($position > count($steps) + 1) {
                    throw new RouteStepOrderingException('L’emplacement demandé pour la nouvelle étape est invalide.');
                }

                $previousStep = $position > 1 ? ($steps[$position - 2] ?? null) : null;
                $coordinateSource = $previousStep ?? $this->firstStepCoordinateSource($steps);
                $newPoint = $this->newPoint($draft, $steps === []);
                $this->inheritCoordinates($draft, $newPoint, $coordinateSource);
                $this->orderingService->insertAtPosition($draft, $newPoint, $position);
                $this->refreshDerivedRouteUrl($draft);
                $this->entityManager->flush();

                return $newPoint;
            });
        } catch (RouteStepOrderingException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->studioRedirect($draft, 'section-points');
        } catch (\Throwable) {
            $this->addFlash('error', 'Un conflit a empêché l’ajout de l’étape. Réessayez.');

            return $this->studioRedirect($draft, 'section-points');
        }

        $pointId = $point->getId();
        if ($pointId === null) {
            throw new \LogicException('L’étape ajoutée devrait être enregistrée.');
        }

        if ($point->getLatitude() !== null && $point->getLongitude() !== null) {
            $this->addFlash('success', 'Étape ajoutée. Coordonnées reprises depuis le point précédent ; utilisez le GPS existant pour les ajuster.');
        } else {
            $this->addFlash('success', 'Étape ajoutée sans coordonnées. Utilisez le système GPS existant pour placer ce point.');
        }

        return $this->studioRedirect($draft, $this->pointAnchor($point), $pointId);
    }

    private function reorderPoints(HikeDraft|CityVisitDraft $draft, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $draft);

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonError('Le corps JSON est invalide.', Response::HTTP_BAD_REQUEST);
        }

        $token = $request->headers->get('X-CSRF-TOKEN');
        if (!is_string($token) || !$this->isCsrfTokenValid($this->tokenId($draft, 'reorder'), $token)) {
            return $this->jsonError('Le jeton de sécurité est invalide.', Response::HTTP_FORBIDDEN);
        }

        $orderedIds = $payload['orderedIds'] ?? null;
        if (!is_array($orderedIds) || !array_is_list($orderedIds)) {
            return $this->jsonError('La liste orderedIds est invalide.', Response::HTTP_BAD_REQUEST);
        }

        $validatedIds = [];
        foreach ($orderedIds as $orderedId) {
            if (!is_int($orderedId) || $orderedId < 1) {
                return $this->jsonError('Tous les identifiants d’étape doivent être des entiers positifs.', Response::HTTP_BAD_REQUEST);
            }
            $validatedIds[] = $orderedId;
        }

        try {
            $customizedGps = $this->customizedGpsChanges($payload['customizedGps'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var list<HikePoint|CityVisitPoint> $steps */
            $steps = $this->entityManager->wrapInTransaction(function () use ($draft, $validatedIds, $customizedGps): array {
                $this->lockDraft($draft);
                $orderedSteps = $this->orderingService->reorder($draft, $validatedIds);
                $this->applyCustomizedGpsChanges($orderedSteps, $customizedGps);
                $this->refreshInheritedCoordinatesAfterReorder($orderedSteps);
                $this->refreshDerivedRouteUrl($draft);
                $this->entityManager->flush();

                return $orderedSteps;
            });
        } catch (RouteStepOrderingException $exception) {
            return $this->jsonError($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return $this->jsonError('Un conflit a empêché l’enregistrement de l’ordre.', Response::HTTP_CONFLICT);
        }

        return $this->json([
            'success' => true,
            'steps' => array_map(
                static fn (HikePoint|CityVisitPoint $step): array => [
                    'id' => $step->getId(),
                    'position' => $step->getPosition(),
                ],
                $steps,
            ),
            'gpsStates' => array_map(
                static fn (HikePoint|CityVisitPoint $step): array => [
                    'id' => $step->getId(),
                    'latitude' => $step->getLatitude(),
                    'longitude' => $step->getLongitude(),
                    'accuracy' => $step->getAccuracy(),
                    'inherited' => $step->hasInheritedCoordinates(),
                ],
                $steps,
            ),
        ]);
    }

    private function updatePoint(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $point,
        Request $request,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $draft);
        if (!$this->isCsrfTokenValid($this->tokenId($draft, 'update', $point), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Le formulaire de l’étape GPS a expiré. Réessayez.');

            return $this->studioRedirect($draft, $this->pointAnchor($point));
        }

        [$latitudeIsValid, $latitude] = $this->decimalInput($request, 'latitude', -90, 90, 'La latitude GPS');
        [$longitudeIsValid, $longitude] = $this->decimalInput($request, 'longitude', -180, 180, 'La longitude GPS');
        [$accuracyIsValid, $accuracy] = $this->decimalInput($request, 'accuracy', 0, PHP_FLOAT_MAX, 'La précision GPS');
        if (!$latitudeIsValid || !$longitudeIsValid || !$accuracyIsValid) {
            return $this->studioRedirect($draft, $this->pointAnchor($point));
        }

        if (($latitude === null) !== ($longitude === null)) {
            $this->addFlash('error', 'La latitude et la longitude doivent être renseignées ensemble.');

            return $this->studioRedirect($draft, $this->pointAnchor($point));
        }
        if ($latitude === null) {
            $accuracy = null;
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($draft, $point, $request, $latitude, $longitude, $accuracy): void {
                $this->lockDraft($draft);
                $point
                    ->setTitle($this->nullableText($request->request->getString('title'), 180))
                    ->setNote($this->nullableText($request->request->getString('note')))
                    ->setLatitude($latitude)
                    ->setLongitude($longitude)
                    ->setAccuracy($accuracy)
                    ->setCoordinatesInherited($request->request->getString('coordinatesInherited') === '1');

                if ($point instanceof HikePoint) {
                    $type = HikePointType::tryFrom($request->request->getString('type'));
                    if ($type instanceof HikePointType) {
                        $point->setType($type);
                    }
                } else {
                    $type = CityVisitPointType::tryFrom($request->request->getString('type'));
                    if ($type instanceof CityVisitPointType) {
                        $point->setType($type);
                    }
                }

                $this->refreshDerivedRouteUrl($draft);
                $this->entityManager->flush();
            });
        } catch (\Throwable) {
            $this->addFlash('error', 'Un conflit a empêché l’enregistrement de l’étape. Réessayez.');

            return $this->studioRedirect($draft, $this->pointAnchor($point));
        }

        $this->addFlash('success', 'Étape GPS enregistrée.');

        return $this->studioRedirect($draft, $this->pointAnchor($point));
    }

    private function deletePoint(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $point,
        Request $request,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $draft);
        if (!$this->isCsrfTokenValid($this->tokenId($draft, 'delete', $point), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La suppression de l’étape n’a pas pu être validée.');

            return $this->studioRedirect($draft, $this->pointAnchor($point));
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($draft, $point): void {
                $this->lockDraft($draft);
                $this->orderingService->removeAndCompact($draft, $point);
                $this->refreshDerivedRouteUrl($draft);
                $this->entityManager->flush();
            });
        } catch (RouteStepOrderingException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->studioRedirect($draft, 'section-points');
        } catch (\Throwable) {
            $this->addFlash('error', 'Un conflit a empêché la suppression de l’étape. Réessayez.');

            return $this->studioRedirect($draft, 'section-points');
        }

        $this->addFlash('success', 'Étape supprimée. Les positions restantes ont été compactées.');

        return $this->studioRedirect($draft, 'section-points');
    }

    /**
     * @param list<HikePoint|CityVisitPoint> $steps
     */
    private function firstStepCoordinateSource(array $steps): HikePoint|CityVisitPoint|null
    {
        foreach ($steps as $step) {
            $isStart = ($step instanceof HikePoint && $step->getType() === HikePointType::Start)
                || ($step instanceof CityVisitPoint && $step->getType() === CityVisitPointType::Start);
            if ($isStart && $this->hasCoordinates($step)) {
                return $step;
            }
        }

        // These draft entities do not persist their own GPS coordinates. The
        // Destination coordinates are commune/map centres, not route points,
        // and must never be copied as if an administrator had selected them.
        return null;
    }

    private function newPoint(HikeDraft|CityVisitDraft $draft, bool $firstPoint): HikePoint|CityVisitPoint
    {
        if ($draft instanceof HikeDraft) {
            return (new HikePoint())
                ->setType($firstPoint ? HikePointType::Start : HikePointType::Other)
                ->setCoordinatesInherited(true);
        }

        return (new CityVisitPoint())
            ->setType($firstPoint ? CityVisitPointType::Start : CityVisitPointType::Other)
            ->setCoordinatesInherited(true);
    }

    private function inheritCoordinates(
        HikeDraft|CityVisitDraft $draft,
        HikePoint|CityVisitPoint $point,
        HikePoint|CityVisitPoint|null $source,
    ): void {
        if ($source !== null && $this->hasCoordinates($source)) {
            $point
                ->setLatitude($source->getLatitude())
                ->setLongitude($source->getLongitude())
                ->setAccuracy($source->getAccuracy());
        }

        if ($source instanceof HikePoint || $source instanceof CityVisitPoint) {
            $point
                ->setDetectedCommuneName($source->getDetectedCommuneName())
                ->setDetectedCommuneCode($source->getDetectedCommuneCode())
                ->setDetectedDepartmentName($source->getDetectedDepartmentName())
                ->setDetectedRegionName($source->getDetectedRegionName());

            return;
        }

        $point
            ->setDetectedCommuneName($draft->getDetectedCommuneName())
            ->setDetectedCommuneCode($draft->getDetectedCommuneCode())
            ->setDetectedDepartmentName($draft->getDetectedDepartmentName())
            ->setDetectedRegionName($draft->getDetectedRegionName());
    }

    private function hasCoordinates(HikePoint|CityVisitPoint $source): bool
    {
        $latitude = $source->getLatitude();
        $longitude = $source->getLongitude();

        return $latitude !== null && $latitude >= -90 && $latitude <= 90
            && $longitude !== null && $longitude >= -180 && $longitude <= 180;
    }

    /**
     * A newly inserted step remains linked to its predecessor until an
     * administrator customises its GPS. Existing/customised steps are never
     * touched here. Keeping this outside the ordering service preserves that
     * service's strict order-only responsibility.
     *
     * @param list<HikePoint|CityVisitPoint> $steps
     */
    private function refreshInheritedCoordinatesAfterReorder(array $steps): void
    {
        foreach ($steps as $index => $step) {
            if (!$step->hasInheritedCoordinates()) {
                continue;
            }

            $source = $index > 0 ? $steps[$index - 1] : $this->firstStepCoordinateSource($steps);
            if ($source === $step) {
                continue;
            }

            if (($source instanceof HikePoint || $source instanceof CityVisitPoint) && $this->hasCoordinates($source)) {
                $step
                    ->setLatitude($source->getLatitude())
                    ->setLongitude($source->getLongitude())
                    ->setAccuracy($source->getAccuracy());

                continue;
            }

            $step
                ->setLatitude(null)
                ->setLongitude(null)
                ->setAccuracy(null);
        }
    }

    /**
     * @param list<HikePoint|CityVisitPoint> $steps
     * @param array<int, array{latitude: float|null, longitude: float|null, accuracy: float|null}> $changes
     */
    private function applyCustomizedGpsChanges(array $steps, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $stepsById = [];
        foreach ($steps as $step) {
            $stepId = $step->getId();
            if ($stepId !== null) {
                $stepsById[$stepId] = $step;
            }
        }

        foreach ($changes as $stepId => $coordinates) {
            $step = $stepsById[$stepId] ?? null;
            if (!$step instanceof HikePoint && !$step instanceof CityVisitPoint) {
                throw new RouteStepOrderingException('Une personnalisation GPS n’appartient pas à ce parcours.');
            }

            $step
                ->setLatitude($coordinates['latitude'])
                ->setLongitude($coordinates['longitude'])
                ->setAccuracy($coordinates['accuracy'])
                ->setCoordinatesInherited(false);
        }
    }

    /**
     * @return array<int, array{latitude: float|null, longitude: float|null, accuracy: float|null}>
     */
    private function customizedGpsChanges(mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new \InvalidArgumentException('La liste customizedGps est invalide.');
        }

        $changes = [];
        foreach ($payload as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Chaque personnalisation GPS doit être un objet.');
            }

            $expectedKeys = ['id', 'latitude', 'longitude', 'accuracy'];
            foreach ($expectedKeys as $expectedKey) {
                if (!array_key_exists($expectedKey, $item)) {
                    throw new \InvalidArgumentException('Chaque personnalisation GPS doit fournir id, latitude, longitude et accuracy.');
                }
            }
            if (array_diff(array_keys($item), $expectedKeys) !== []) {
                throw new \InvalidArgumentException('Une personnalisation GPS contient un champ inattendu.');
            }

            $stepId = $item['id'];
            if (!is_int($stepId) || $stepId < 1 || isset($changes[$stepId])) {
                throw new \InvalidArgumentException('Les identifiants customizedGps doivent être uniques et positifs.');
            }

            $latitude = $this->decimalPayload($item['latitude'], -90, 90, 'La latitude GPS personnalisée');
            $longitude = $this->decimalPayload($item['longitude'], -180, 180, 'La longitude GPS personnalisée');
            $accuracy = $this->decimalPayload($item['accuracy'], 0, PHP_FLOAT_MAX, 'La précision GPS personnalisée');
            if (($latitude === null) !== ($longitude === null)) {
                throw new \InvalidArgumentException('La latitude et la longitude GPS personnalisées doivent être renseignées ensemble.');
            }
            if ($latitude === null) {
                $accuracy = null;
            }

            $changes[$stepId] = [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy,
            ];
        }

        return $changes;
    }

    private function decimalPayload(mixed $value, float $minimum, float $maximum, string $label): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException($label.' doit être un nombre valide.');
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException($label.' doit être un nombre valide.');
        }

        $number = (float) $normalized;
        if (!is_finite($number) || $number < $minimum || $number > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s doit être comprise entre %s et %s.', $label, $minimum, $maximum));
        }

        return $number;
    }

    private function lockDraft(HikeDraft|CityVisitDraft $draft): void
    {
        if ($draft->getId() !== null) {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
        }
    }

    private function refreshDerivedRouteUrl(HikeDraft|CityVisitDraft $draft): void
    {
        if (!$draft instanceof CityVisitDraft) {
            return;
        }

        $validPoints = array_values(array_filter(
            $this->orderingService->orderedSteps($draft),
            fn (HikePoint|CityVisitPoint $point): bool => $this->hasCoordinates($point),
        ));

        if (count($validPoints) < 2) {
            $draft->setGoogleMapsUrl(null);

            return;
        }

        $coordinates = array_map(
            static fn (HikePoint|CityVisitPoint $point): string => sprintf('%.7F,%.7F', (float) $point->getLatitude(), (float) $point->getLongitude()),
            $validPoints,
        );
        $origin = array_shift($coordinates);
        $destination = array_pop($coordinates);
        $parameters = [
            'api' => '1',
            'origin' => (string) $origin,
            'destination' => (string) $destination,
            'travelmode' => 'walking',
        ];
        if ($coordinates !== []) {
            $parameters['waypoints'] = implode('|', $coordinates);
        }

        $draft->setGoogleMapsUrl('https://www.google.com/maps/dir/?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));
    }

    private function tokenId(
        HikeDraft|CityVisitDraft $draft,
        string $action,
        HikePoint|CityVisitPoint|null $point = null,
    ): string {
        $prefix = $draft instanceof HikeDraft ? 'studio_hike_point_' : 'studio_city_visit_point_';
        $suffix = $point?->getId() ?? $draft->getId();

        return $prefix.$action.'_'.$suffix;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    /** @return array{bool, float|null} */
    private function decimalInput(
        Request $request,
        string $field,
        float $minimum,
        float $maximum,
        string $label,
    ): array {
        $rawValue = $request->request->all()[$field] ?? null;
        if ($rawValue === null || (is_string($rawValue) && trim($rawValue) === '')) {
            return [true, null];
        }

        if (!is_string($rawValue) && !is_int($rawValue) && !is_float($rawValue)) {
            $this->addFlash('error', $label.' doit être un nombre valide.');

            return [false, null];
        }

        $normalizedValue = str_replace(',', '.', trim((string) $rawValue));
        if (!is_numeric($normalizedValue)) {
            $this->addFlash('error', $label.' doit être un nombre valide.');

            return [false, null];
        }

        $value = (float) $normalizedValue;
        if (!is_finite($value) || $value < $minimum || $value > $maximum) {
            $this->addFlash('error', sprintf('%s doit être comprise entre %s et %s.', $label, $minimum, $maximum));

            return [false, null];
        }

        return [true, $value];
    }

    private function nullableText(string $value, ?int $maximumLength = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $maximumLength === null ? $value : mb_substr($value, 0, $maximumLength);
    }

    private function pointAnchor(HikePoint|CityVisitPoint $point): string
    {
        return ($point instanceof HikePoint ? 'point-' : 'city-visit-point-').$point->getId();
    }

    private function studioRedirect(
        HikeDraft|CityVisitDraft $draft,
        string $fragment,
        ?int $newPointId = null,
    ): RedirectResponse {
        $parameters = [
            'id' => $draft->getId(),
            '_fragment' => $fragment,
        ];
        if ($newPointId !== null) {
            $parameters['newPoint'] = $newPointId;
        }

        return $this->redirectToRoute(
            $draft instanceof HikeDraft ? 'admin_studio_hike_edit' : 'admin_studio_city_visit_edit',
            $parameters,
        );
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return $this->json([
            'success' => false,
            'error' => $message,
        ], $status);
    }
}
