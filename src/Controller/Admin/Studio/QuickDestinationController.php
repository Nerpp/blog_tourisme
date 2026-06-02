<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraft;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\Place;
use App\Enum\DestinationType;
use App\Repository\DestinationRepository;
use App\Security\Voter\AdminAccessVoter;
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
    private const QUICK_CITY_VISIT_DESTINATION_SESSION_KEY = 'quick_city_visit_destination_id';
    private const QUICK_CITY_VISIT_DESTINATION_POSTAL_CODE_SESSION_KEY = 'quick_city_visit_destination_postal_code';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DestinationRepository $destinationRepository,
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
                null,
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
                null,
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
                null,
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
                ->setSlug($this->createUniqueSlug($name))
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
            $destination = $this->destinationRepository->findOneBy([
                'code' => $code,
                'type' => $type,
            ]);

            if ($destination instanceof Destination) {
                return $destination;
            }
        }

        return $this->destinationRepository->findOneBy([
            'name' => $name,
            'type' => $type,
        ]);
    }

    private function codeForType(DestinationType $type, ?string $code): ?string
    {
        return match ($type) {
            DestinationType::City,
            DestinationType::Area => $code,
            DestinationType::Country,
            DestinationType::Region,
            DestinationType::Department => null,
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

    private function createUniqueSlug(string $name): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($name));
        $baseSlug = trim($baseSlug, '-') ?: 'destination';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->destinationRepository->findOneBy(['slug' => $slug]) instanceof Destination) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
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
