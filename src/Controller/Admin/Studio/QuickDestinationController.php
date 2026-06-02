<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\Place;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Repository\CityVisitDraftRepository;
use App\Repository\DestinationRepository;
use App\Repository\HikeDraftRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Service\GeographicHierarchyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/studio/destinations')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class QuickDestinationController extends AbstractController
{
    private const QUICK_HIKE_DESTINATION_SESSION_KEY = 'quick_hike_destination_id';
    private const QUICK_HIKE_DESTINATION_POSTAL_CODE_SESSION_KEY = 'quick_hike_destination_postal_code';
    private const QUICK_HIKE_COMMUNE_SESSION_KEY = 'quick_hike_commune';
    private const QUICK_CITY_VISIT_DESTINATION_SESSION_KEY = 'quick_city_visit_destination_id';
    private const QUICK_CITY_VISIT_DESTINATION_POSTAL_CODE_SESSION_KEY = 'quick_city_visit_destination_postal_code';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DestinationRepository $destinationRepository,
        private readonly HikeDraftRepository $hikeDraftRepository,
        private readonly CityVisitDraftRepository $cityVisitDraftRepository,
        private readonly GeographicHierarchyResolver $geographicHierarchyResolver,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('/quick-create', name: 'admin_studio_destination_quick_create', methods: ['POST'])]
    public function quickCreate(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_destination_quick_create', (string) $request->request->get('_token'))) {
            return $this->errorResponse($request, 'Le formulaire a expiré. Réessayez.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->isQuickHikeFrenchCommune($request)) {
            return $this->createQuickHikeFromCommune($request);
        }

        if ($this->isQuickCityVisitFrenchCommune($request)) {
            return $this->createQuickCityVisitFromCommune($request);
        }

        $requestedType = DestinationType::tryFrom($request->request->getString('type')) ?? DestinationType::Area;
        $parent = $this->findParentDestination($request);
        $destination = $parent instanceof Destination
            ? $this->resolveManualDestination($request, $requestedType, $parent)
            : $this->resolveDestinationHierarchy($request, $requestedType);

        if (!$destination instanceof Destination) {
            return $this->errorResponse($request, 'Renseignez au moins le pays, la région ou le lieu.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $target = $this->findTarget($request);
        if ($target instanceof HikeDraft || $target instanceof CityVisitDraft || $target instanceof Place) {
            $target->setDestination($destination);
        }

        $this->entityManager->flush();
        $this->rememberPreparedDestination($request, $destination);

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => true,
                'destination' => [
                    'id' => $destination->getId(),
                    'name' => $destination->getName(),
                    'type' => $destination->getType()->value,
                ],
            ]);
        }

        $this->addFlash('success', sprintf('Destination "%s" enregistrée.', $destination->getName()));

        return new RedirectResponse($this->safeReturnUrl($request));
    }

    private function rememberPreparedDestination(Request $request, Destination $destination): void
    {
        $destinationId = $destination->getId();
        if ($destinationId === null) {
            return;
        }

        $contextType = $request->request->getString('contextType') ?: $request->request->getString('targetType');
        $session = $request->getSession();
        $postalCode = $this->nullIfBlank($request->request->getString('postalCode'));

        if ($contextType === 'quick_hike') {
            $session->set(self::QUICK_HIKE_DESTINATION_SESSION_KEY, $destinationId);
            $session->remove(self::QUICK_HIKE_COMMUNE_SESSION_KEY);
            if ($postalCode !== null) {
                $session->set(self::QUICK_HIKE_DESTINATION_POSTAL_CODE_SESSION_KEY, $postalCode);
            } else {
                $session->remove(self::QUICK_HIKE_DESTINATION_POSTAL_CODE_SESSION_KEY);
            }

            return;
        }

        if ($contextType === 'quick_city_visit') {
            $session->set(self::QUICK_CITY_VISIT_DESTINATION_SESSION_KEY, $destinationId);
            if ($postalCode !== null) {
                $session->set(self::QUICK_CITY_VISIT_DESTINATION_POSTAL_CODE_SESSION_KEY, $postalCode);
            } else {
                $session->remove(self::QUICK_CITY_VISIT_DESTINATION_POSTAL_CODE_SESSION_KEY);
            }
        }
    }

    private function isQuickHikeFrenchCommune(Request $request): bool
    {
        $contextType = $request->request->getString('contextType') ?: $request->request->getString('targetType');

        return $contextType === 'quick_hike'
            && $request->request->getString('type') === DestinationType::City->value
            && $this->truncate($request->request->getString('countryName'), 150) === 'France'
            && $this->truncate($request->request->getString('cityName'), 150) !== ''
            && $this->nullIfBlank($request->request->getString('code')) !== null;
    }

    private function isQuickCityVisitFrenchCommune(Request $request): bool
    {
        $contextType = $request->request->getString('contextType') ?: $request->request->getString('targetType');

        return $contextType === 'quick_city_visit'
            && $request->request->getString('type') === DestinationType::City->value
            && $this->truncate($request->request->getString('countryName'), 150) === 'France'
            && $this->truncate($request->request->getString('cityName'), 150) !== ''
            && $this->nullIfBlank($request->request->getString('code')) !== null;
    }

    private function createQuickHikeFromCommune(Request $request): Response
    {
        $commune = $this->quickCommuneData($request);
        $title = sprintf('Randonnée à %s', $commune['communeName']);
        $draft = (new HikeDraft())
            ->setTitle($title)
            ->setSlug($this->createUniqueHikeSlug($title))
            ->setStatus(HikeDraftStatus::Draft)
            ->setDetectedCommuneName($commune['communeName'])
            ->setDetectedCommuneCode($commune['communeInseeCode'])
            ->setDetectedDepartmentName($commune['departmentName'])
            ->setDetectedRegionName($commune['regionName'])
            ->setGeographicDestination($this->geographicHierarchyResolver->resolveCommune(
                $commune['communeName'],
                $commune['communeInseeCode'],
                $commune['departmentName'],
                $commune['regionName'],
                $commune['country'],
                $commune['latitude'],
                $commune['longitude'],
                $commune['departmentCode'],
            ));

        $user = $this->getUser();
        if ($user instanceof User) {
            $draft->setCreatedBy($user);
        }

        $request->getSession()->remove(self::QUICK_HIKE_DESTINATION_SESSION_KEY);
        $request->getSession()->remove(self::QUICK_HIKE_DESTINATION_POSTAL_CODE_SESSION_KEY);
        $request->getSession()->remove(self::QUICK_HIKE_COMMUNE_SESSION_KEY);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => true,
                'commune' => $commune,
                'redirect' => $this->generateUrl('admin_studio_hike_edit', ['id' => $draft->getId()]),
            ]);
        }

        $this->addFlash('success', sprintf('Randonnée créée dans la commune "%s".', $commune['communeName']));

        return $this->redirectToRoute('admin_studio_hike_edit', ['id' => $draft->getId()]);
    }

    private function createQuickCityVisitFromCommune(Request $request): Response
    {
        $commune = $this->quickCommuneData($request);
        $title = sprintf('Visite de ville à %s', $commune['communeName']);
        $draft = (new CityVisitDraft())
            ->setTitle($title)
            ->setSlug($this->createUniqueCityVisitSlug($title))
            ->setStatus(CityVisitDraftStatus::Draft)
            ->setDetectedCommuneName($commune['communeName'])
            ->setDetectedCommuneCode($commune['communeInseeCode'])
            ->setDetectedDepartmentName($commune['departmentName'])
            ->setDetectedRegionName($commune['regionName'])
            ->setGeographicDestination($this->geographicHierarchyResolver->resolveCommune(
                $commune['communeName'],
                $commune['communeInseeCode'],
                $commune['departmentName'],
                $commune['regionName'],
                $commune['country'],
                $commune['latitude'],
                $commune['longitude'],
                $commune['departmentCode'],
            ));

        $user = $this->getUser();
        if ($user instanceof User) {
            $draft->setCreatedBy($user);
        }

        $request->getSession()->remove(self::QUICK_CITY_VISIT_DESTINATION_SESSION_KEY);
        $request->getSession()->remove(self::QUICK_CITY_VISIT_DESTINATION_POSTAL_CODE_SESSION_KEY);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'ok' => true,
                'commune' => $commune,
                'redirect' => $this->generateUrl('admin_studio_city_visit_edit', ['id' => $draft->getId()]),
            ]);
        }

        $this->addFlash('success', sprintf('Visite de ville créée dans la commune "%s".', $commune['communeName']));

        return $this->redirectToRoute('admin_studio_city_visit_edit', ['id' => $draft->getId()]);
    }

    /**
     * @return array{
     *     communeName: string,
     *     communeInseeCode: string,
     *     postalCode: string|null,
     *     departmentName: string|null,
     *     departmentCode: string|null,
     *     regionName: string|null,
     *     country: string,
     *     latitude: float|null,
     *     longitude: float|null
     * }
     */
    private function quickCommuneData(Request $request): array
    {
        $communeCode = $this->truncate($request->request->getString('code'), 20);

        return [
            'communeName' => $this->truncate($request->request->getString('cityName'), 150),
            'communeInseeCode' => $communeCode,
            'postalCode' => $this->nullIfBlank($request->request->getString('postalCode')),
            'departmentName' => $this->nullIfBlank($this->truncate($request->request->getString('departmentName'), 150)),
            'departmentCode' => $this->nullIfBlank($request->request->getString('departmentCode')) ?? $this->departmentCodeFromCommuneCode($communeCode),
            'regionName' => $this->nullIfBlank($this->truncate($request->request->getString('regionName'), 150)),
            'country' => 'France',
            'latitude' => $this->nullableFloat($request->request->get('latitude')),
            'longitude' => $this->nullableFloat($request->request->get('longitude')),
        ];
    }

    private function departmentCodeFromCommuneCode(string $communeCode): ?string
    {
        if ($communeCode === '') {
            return null;
        }

        return str_starts_with($communeCode, '97') ? substr($communeCode, 0, 3) : substr($communeCode, 0, 2);
    }

    private function createUniqueHikeSlug(string $title): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($title));
        $baseSlug = trim($baseSlug, '-') ?: 'randonnee';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->hikeDraftRepository->findOneBy(['slug' => $slug]) instanceof HikeDraft) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }

    private function createUniqueCityVisitSlug(string $title): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($title));
        $baseSlug = trim($baseSlug, '-') ?: 'visite-de-ville';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->cityVisitDraftRepository->findOneBy(['slug' => $slug]) instanceof CityVisitDraft) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }

    private function findParentDestination(Request $request): ?Destination
    {
        $parentId = $this->nullableInt($request->request->get('parent'));

        return $parentId !== null ? $this->destinationRepository->find($parentId) : null;
    }

    private function findTarget(Request $request): HikeDraft|CityVisitDraft|Place|null
    {
        $targetId = $this->nullableInt($request->request->get('contextId') ?: $request->request->get('targetId'));
        if ($targetId === null) {
            return null;
        }

        $targetType = $request->request->getString('contextType') ?: $request->request->getString('targetType');

        return match ($targetType) {
            'hike' => $this->entityManager->find(HikeDraft::class, $targetId),
            'city_visit' => $this->entityManager->find(CityVisitDraft::class, $targetId),
            'place' => $this->entityManager->find(Place::class, $targetId),
            default => null,
        };
    }

    private function resolveManualDestination(Request $request, DestinationType $requestedType, Destination $parent): ?Destination
    {
        $name = $this->destinationName($request, $requestedType);
        if ($name === '') {
            return null;
        }

        return $this->findOrCreateDestinationNode(
            $name,
            $requestedType,
            $parent,
            $this->codeForType($requestedType, $this->nullIfBlank($request->request->getString('code'))),
            $this->nullableFloat($request->request->get('latitude')),
            $this->nullableFloat($request->request->get('longitude')),
        );
    }

    private function resolveDestinationHierarchy(Request $request, DestinationType $requestedType): ?Destination
    {
        $countryName = $this->truncate($request->request->getString('countryName'), 150);
        $regionName = $this->truncate($request->request->getString('regionName'), 150);
        $departmentName = $this->truncate($request->request->getString('departmentName'), 150);
        $cityName = $this->truncate($request->request->getString('cityName'), 150);
        $areaName = $this->truncate($request->request->getString('areaName'), 150);
        $fallbackName = $this->truncate($request->request->getString('name'), 150);

        if ($fallbackName !== '') {
            match ($requestedType) {
                DestinationType::Country => $countryName = $countryName ?: $fallbackName,
                DestinationType::Region => $regionName = $regionName ?: $fallbackName,
                DestinationType::Department => $departmentName = $departmentName ?: $fallbackName,
                DestinationType::City => $cityName = $cityName ?: $fallbackName,
                DestinationType::Area => $areaName = $areaName ?: $fallbackName,
            };
        }

        if ($countryName === '' && $regionName === '' && $departmentName === '' && $cityName === '' && $areaName === '') {
            return null;
        }

        $code = $this->nullIfBlank($request->request->getString('code'));
        $countryCode = $this->countryCode(
            $countryName,
            $requestedType === DestinationType::Country ? $code : null,
        );
        $departmentCode = $this->nullIfBlank($request->request->getString('departmentCode'))
            ?? $this->departmentCodeFromCommuneCode($code ?? '');
        $regionCode = $this->nullIfBlank($request->request->getString('regionCode'))
            ?? ($requestedType === DestinationType::Region ? $code : null)
            ?? $this->regionCode($regionName, $countryCode);
        $latitude = $this->nullableFloat($request->request->get('latitude'));
        $longitude = $this->nullableFloat($request->request->get('longitude'));
        $coordinatesType = $this->mostPreciseType($countryName, $regionName, $departmentName, $cityName, $areaName);

        $country = null;
        $region = null;
        $department = null;
        $city = null;
        $area = null;
        $parent = null;

        if ($countryName !== '') {
            [$nodeLatitude, $nodeLongitude] = $this->coordinatesForType(DestinationType::Country, $coordinatesType, $latitude, $longitude);
            $country = $this->findOrCreateDestinationNode(
                $countryName,
                DestinationType::Country,
                null,
                $countryCode,
                $nodeLatitude,
                $nodeLongitude,
            );
            $parent = $country;
        }

        if ($regionName !== '') {
            [$nodeLatitude, $nodeLongitude] = $this->coordinatesForType(DestinationType::Region, $coordinatesType, $latitude, $longitude);
            $region = $this->findOrCreateDestinationNode(
                $regionName,
                DestinationType::Region,
                $parent,
                $regionCode,
                $nodeLatitude,
                $nodeLongitude,
            );
            $parent = $region;
        }

        if ($departmentName !== '') {
            [$nodeLatitude, $nodeLongitude] = $this->coordinatesForType(DestinationType::Department, $coordinatesType, $latitude, $longitude);
            $department = $this->findOrCreateDestinationNode(
                $departmentName,
                DestinationType::Department,
                $parent,
                $departmentCode,
                $nodeLatitude,
                $nodeLongitude,
            );
            $parent = $department;
        }

        if ($cityName !== '') {
            [$nodeLatitude, $nodeLongitude] = $this->coordinatesForType(DestinationType::City, $coordinatesType, $latitude, $longitude);
            $city = $this->findOrCreateDestinationNode(
                $cityName,
                DestinationType::City,
                $parent,
                $this->codeForType(DestinationType::City, $code),
                $nodeLatitude,
                $nodeLongitude,
            );
            $parent = $city;
        }

        if ($areaName !== '') {
            [$nodeLatitude, $nodeLongitude] = $this->coordinatesForType(DestinationType::Area, $coordinatesType, $latitude, $longitude);
            $area = $this->findOrCreateDestinationNode(
                $areaName,
                DestinationType::Area,
                $parent,
                $this->codeForType(DestinationType::Area, $code),
                $nodeLatitude,
                $nodeLongitude,
            );
        }

        return match ($requestedType) {
            DestinationType::Country => $country,
            DestinationType::Region => $region,
            DestinationType::Department => $department,
            DestinationType::City => $city,
            DestinationType::Area => $area ?? $city ?? $department ?? $region ?? $country,
        };
    }

    private function destinationName(Request $request, DestinationType $type): string
    {
        $name = $this->truncate($request->request->getString('name'), 150);
        $countryName = $this->truncate($request->request->getString('countryName'), 150);
        $regionName = $this->truncate($request->request->getString('regionName'), 150);
        $departmentName = $this->truncate($request->request->getString('departmentName'), 150);
        $cityName = $this->truncate($request->request->getString('cityName'), 150);
        $areaName = $this->truncate($request->request->getString('areaName'), 150);

        $candidate = match ($type) {
            DestinationType::Country => $countryName,
            DestinationType::Region => $regionName,
            DestinationType::Department => $departmentName,
            DestinationType::City => $cityName,
            DestinationType::Area => $areaName ?: $cityName ?: $departmentName ?: $regionName ?: $countryName,
        };

        return $candidate !== '' ? $candidate : $name;
    }

    private function findOrCreateDestinationNode(
        string $name,
        DestinationType $type,
        ?Destination $parent,
        ?string $code,
        ?float $latitude,
        ?float $longitude,
    ): Destination
    {
        $destination = $this->findReusableDestination($name, $type, $code);
        if (!$destination instanceof Destination) {
            $destination = (new Destination())
                ->setName($name)
                ->setSlug($this->createUniqueSlug($name, $type, $code, $parent))
                ->setType($type)
                ->setCode($code);

            $this->entityManager->persist($destination);
        } else {
            $destination->setName($name);
            if ($code !== null) {
                $destination->setCode($code);
            }
        }

        if ($parent instanceof Destination && !$this->sameDestination($destination, $parent) && !$this->sameDestination($destination->getParent(), $parent)) {
            $destination->setParent($parent);
        }

        if (!$parent instanceof Destination && $type === DestinationType::Country && $destination->getParent() instanceof Destination) {
            $destination->setParent(null);
        }

        if ($latitude !== null) {
            $destination->setLatitude($latitude);
        }

        if ($longitude !== null) {
            $destination->setLongitude($longitude);
        }

        return $destination;
    }

    private function findReusableDestination(string $name, DestinationType $type, ?string $code): ?Destination
    {
        if ($code !== null) {
            $destination = $this->scheduledDestinationByCode($type, $code);
            if ($destination instanceof Destination) {
                return $destination;
            }

            $destination = $this->destinationRepository->findOneBy([
                'code' => $code,
                'type' => $type,
            ]);

            if ($destination instanceof Destination) {
                return $destination;
            }

            if ($type === DestinationType::City) {
                return null;
            }
        }

        $destination = $this->destinationRepository->findOneBy([
            'name' => $name,
            'type' => $type,
        ]);

        if ($destination instanceof Destination && ($code === null || $destination->getCode() === null)) {
            return $destination;
        }

        return null;
    }

    private function scheduledDestinationByCode(DestinationType $type, string $code): ?Destination
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Destination && $entity->getType() === $type && $entity->getCode() === $code) {
                return $entity;
            }
        }

        return null;
    }

    private function codeForType(DestinationType $type, ?string $code): ?string
    {
        return match ($type) {
            DestinationType::City,
            DestinationType::Area => $code,
            DestinationType::Region,
            DestinationType::Department,
            DestinationType::Country => $code,
        };
    }

    private function mostPreciseType(string $countryName, string $regionName, string $departmentName, string $cityName, string $areaName): DestinationType
    {
        if ($areaName !== '') {
            return DestinationType::Area;
        }

        if ($cityName !== '') {
            return DestinationType::City;
        }

        if ($departmentName !== '') {
            return DestinationType::Department;
        }

        if ($regionName !== '') {
            return DestinationType::Region;
        }

        return DestinationType::Country;
    }

    /** @return array{0: ?float, 1: ?float} */
    private function coordinatesForType(DestinationType $type, DestinationType $coordinatesType, ?float $latitude, ?float $longitude): array
    {
        return $type === $coordinatesType ? [$latitude, $longitude] : [null, null];
    }

    private function sameDestination(?Destination $first, ?Destination $second): bool
    {
        if (!$first instanceof Destination || !$second instanceof Destination) {
            return $first === $second;
        }

        if ($first === $second) {
            return true;
        }

        return $first->getId() !== null && $first->getId() === $second->getId();
    }

    private function createUniqueSlug(string $name, DestinationType $type, ?string $code, ?Destination $parent): string
    {
        $baseParts = [$name];
        if ($code !== null) {
            $baseParts[] = $code;
        } elseif ($type !== DestinationType::Country) {
            $baseParts[] = $type->value;
        }

        $baseSlug = $this->slug(implode(' ', $baseParts));
        $slug = $baseSlug;
        $fallbacks = [
            sprintf('%s-%s', $baseSlug, $type->value),
        ];

        if ($parent instanceof Destination) {
            $parentSlug = $parent->getSlug();
            $parentToken = $parentSlug !== null && $parentSlug !== ''
                ? $parentSlug
                : sprintf('parent-%s', (string) ($parent->getId() ?? $this->slug($parent->getName() ?? 'destination')));
            $fallbacks[] = sprintf('%s-%s', $baseSlug, $parentToken);
        }

        foreach (array_values(array_unique($fallbacks)) as $candidate) {
            if (!$this->slugExists($slug)) {
                return $slug;
            }

            $slug = $this->truncateSlug($candidate);
        }

        $suffix = 2;
        while ($this->slugExists($slug)) {
            $suffixToken = sprintf('-%d', $suffix);
            $slug = $this->truncateSlug($baseSlug, strlen($suffixToken)).$suffixToken;
            ++$suffix;
        }

        return $slug;
    }

    private function slug(string $value): string
    {
        return trim(strtolower((string) $this->slugger->slug($value)), '-') ?: 'destination';
    }

    private function slugExists(string $slug): bool
    {
        if ($this->destinationRepository->findOneBy(['slug' => $slug]) instanceof Destination) {
            return true;
        }

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Destination && $entity->getSlug() === $slug) {
                return true;
            }
        }

        return false;
    }

    private function truncateSlug(string $slug, int $reservedLength = 0): string
    {
        $maxLength = 180 - $reservedLength;

        return rtrim(substr($slug, 0, $maxLength), '-') ?: 'destination';
    }

    private function countryCode(string $countryName, ?string $countryCode): ?string
    {
        if ($countryCode !== null) {
            return strtoupper($countryCode);
        }

        return $this->slug($countryName) === 'france' ? 'FR' : null;
    }

    private function regionCode(?string $regionName, ?string $countryCode): ?string
    {
        if ($regionName === null || $regionName === '' || $countryCode !== 'FR') {
            return null;
        }

        return [
            'guadeloupe' => '01',
            'martinique' => '02',
            'guyane' => '03',
            'la-reunion' => '04',
            'mayotte' => '06',
            'ile-de-france' => '11',
            'centre-val-de-loire' => '24',
            'bourgogne-franche-comte' => '27',
            'normandie' => '28',
            'hauts-de-france' => '32',
            'grand-est' => '44',
            'pays-de-la-loire' => '52',
            'bretagne' => '53',
            'nouvelle-aquitaine' => '75',
            'occitanie' => '76',
            'auvergne-rhone-alpes' => '84',
            'provence-alpes-cote-d-azur' => '93',
            'corse' => '94',
        ][$this->slug($regionName)] ?? null;
    }

    private function errorResponse(Request $request, string $message, int $status): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['ok' => false, 'message' => $message], $status);
        }

        $this->addFlash('error', $message);

        return new RedirectResponse($this->safeReturnUrl($request));
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json')
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
    }

    private function safeReturnUrl(Request $request): string
    {
        $returnUrl = $request->request->getString('returnUrl');

        return str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')
            ? $returnUrl
            : $this->generateUrl('admin');
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

    private function truncate(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
