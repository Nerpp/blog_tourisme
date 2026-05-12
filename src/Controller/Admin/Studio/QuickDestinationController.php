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

        $type = DestinationType::tryFrom($request->request->getString('type')) ?? DestinationType::Area;
        $name = $this->destinationName($request, $type);
        if ($name === '') {
            return $this->errorResponse($request, 'Renseignez au moins le pays, la région ou le lieu.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $parent = $this->findParentDestination($request);
        if (!$parent instanceof Destination && $type !== DestinationType::Country) {
            $parent = $this->findOrCreateCountry($request);
        }

        $code = $this->nullIfBlank($request->request->getString('code'));
        $latitude = $this->nullableFloat($request->request->get('latitude'));
        $longitude = $this->nullableFloat($request->request->get('longitude'));

        $destination = $this->findReusableDestination($name, $type, $code);
        if (!$destination instanceof Destination) {
            $destination = (new Destination())
                ->setName($name)
                ->setSlug($this->createUniqueSlug($name))
                ->setType($type)
                ->setCode($code);

            $this->entityManager->persist($destination);
        }

        if (!$destination->getParent() instanceof Destination && $parent instanceof Destination) {
            $destination->setParent($parent);
        }

        if ($latitude !== null || $destination->getLatitude() === null) {
            $destination->setLatitude($latitude);
        }

        if ($longitude !== null || $destination->getLongitude() === null) {
            $destination->setLongitude($longitude);
        }

        $target = $this->findTarget($request);
        if ($target instanceof HikeDraft || $target instanceof CityVisitDraft || $target instanceof Place) {
            $target->setDestination($destination);
        }

        $this->entityManager->flush();

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

    private function destinationName(Request $request, DestinationType $type): string
    {
        $name = $this->truncate($request->request->getString('name'), 150);
        $countryName = $this->truncate($request->request->getString('countryName'), 150);
        $regionName = $this->truncate($request->request->getString('regionName'), 150);
        $departmentName = $this->truncate($request->request->getString('departmentName'), 150);
        $cityName = $this->truncate($request->request->getString('cityName'), 150);

        $candidate = match ($type) {
            DestinationType::Country => $countryName,
            DestinationType::Region => $regionName,
            DestinationType::Department => $departmentName,
            DestinationType::City => $cityName,
            DestinationType::Area => $cityName ?: $departmentName ?: $regionName ?: $countryName,
        };

        return $candidate !== '' ? $candidate : $name;
    }

    private function findOrCreateCountry(Request $request): ?Destination
    {
        $countryName = $this->truncate($request->request->getString('countryName'), 150);
        if ($countryName === '') {
            return null;
        }

        $country = $this->findReusableDestination($countryName, DestinationType::Country, null);
        if ($country instanceof Destination) {
            return $country;
        }

        $country = (new Destination())
            ->setName($countryName)
            ->setSlug($this->createUniqueSlug($countryName))
            ->setType(DestinationType::Country);

        $this->entityManager->persist($country);

        return $country;
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
