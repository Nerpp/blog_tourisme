<?php

namespace App\Controller\Admin;

use App\Entity\Destination;
use App\Enum\DestinationType;
use App\Repository\DestinationRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminDestinationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('/admin/destinations', name: 'admin_destinations_index', methods: ['GET'])]
    public function index(DestinationRepository $destinationRepository): Response
    {
        return $this->render('admin/destinations/index.html.twig', [
            'destinations' => $destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC'], 200),
            'type_labels' => $this->typeLabels(),
        ]);
    }

    #[Route('/admin/destinations/new', name: 'admin_destinations_new', methods: ['GET', 'POST'])]
    public function new(Request $request, DestinationRepository $destinationRepository): Response
    {
        $destination = new Destination();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_destination_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateDestinationFromRequest($destination, $request)) {
                $destination->setSlug($this->createUniqueSlug($destination->getName() ?? 'destination'));
                $this->entityManager->persist($destination);
                $this->entityManager->flush();
                $this->addFlash('success', 'Destination créée.');

                return $this->redirectToRoute('admin_destinations_index');
            }
        }

        return $this->renderDestinationForm($destination, $destinationRepository, 'Nouvelle destination', 'Créer la destination');
    }

    #[Route('/admin/destinations/{id}/edit', name: 'admin_destinations_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Destination $destination, Request $request, DestinationRepository $destinationRepository): Response
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $destination);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_destination_'.$destination->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateDestinationFromRequest($destination, $request)) {
                $this->entityManager->flush();
                $this->addFlash('success', 'Destination enregistrée.');

                return $this->redirectToRoute('admin_destinations_index');
            }
        }

        return $this->renderDestinationForm($destination, $destinationRepository, 'Modifier la destination', 'Enregistrer');
    }

    #[Route('/admin/destinations/{id}/delete', name: 'admin_destinations_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Destination $destination, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_destination_delete_'.$destination->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if ($destination->getChildren()->count() > 0 || $destination->getPlaces()->count() > 0 || $destination->getArticleLinks()->count() > 0) {
            $this->addFlash('warning', 'Cette destination est encore utilisée et ne peut pas être supprimée.');

            return $this->redirectToRoute('admin_destinations_index');
        }

        $this->entityManager->remove($destination);
        $this->entityManager->flush();
        $this->addFlash('success', 'Destination supprimée.');

        return $this->redirectToRoute('admin_destinations_index');
    }

    private function renderDestinationForm(
        Destination $destination,
        DestinationRepository $destinationRepository,
        string $title,
        string $submitLabel,
    ): Response {
        return $this->render('admin/destinations/form.html.twig', [
            'destination' => $destination,
            'destinations' => $destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']),
            'type_options' => $this->typeLabels(),
            'title' => $title,
            'submit_label' => $submitLabel,
        ]);
    }

    private function updateDestinationFromRequest(Destination $destination, Request $request): bool
    {
        $type = DestinationType::tryFrom($request->request->getString('type')) ?? DestinationType::Area;
        $name = $this->destinationName($request, $type);
        if ($name === '') {
            $this->addFlash('error', 'Renseignez au moins un nom de destination.');

            return false;
        }

        $parentId = $this->nullableInt($request->request->get('parent'));
        $parent = $parentId !== null ? $this->entityManager->find(Destination::class, $parentId) : null;
        if ($parent instanceof Destination && $parent->getId() === $destination->getId()) {
            $parent = null;
        }

        $destination
            ->setName($name)
            ->setType($type)
            ->setParent($parent)
            ->setCode($this->nullIfBlank($request->request->getString('code')))
            ->setLatitude($this->nullableFloat($request->request->get('latitude')))
            ->setLongitude($this->nullableFloat($request->request->get('longitude')))
            ->setDescription($this->nullIfBlank($request->request->getString('description')));

        return true;
    }

    /** @return array<string, string> */
    private function typeLabels(): array
    {
        return [
            DestinationType::Country->value => 'Pays',
            DestinationType::Region->value => 'Région',
            DestinationType::Department->value => 'Département / province',
            DestinationType::City->value => 'Ville',
            DestinationType::Area->value => 'Zone / lieu',
        ];
    }

    private function destinationName(Request $request, DestinationType $type): string
    {
        $name = trim($request->request->getString('name'));
        $candidate = match ($type) {
            DestinationType::Country => trim($request->request->getString('countryName')),
            DestinationType::Region => trim($request->request->getString('regionName')),
            DestinationType::Department => trim($request->request->getString('departmentName')),
            DestinationType::City => trim($request->request->getString('cityName')),
            DestinationType::Area => trim($request->request->getString('areaName'))
                ?: trim($request->request->getString('cityName'))
                ?: trim($request->request->getString('departmentName'))
                ?: trim($request->request->getString('regionName'))
                ?: trim($request->request->getString('countryName')),
        };

        return $candidate !== '' ? $candidate : $name;
    }

    private function createUniqueSlug(string $name): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($name));
        $baseSlug = trim($baseSlug, '-') ?: 'destination';
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->entityManager->getRepository(Destination::class)->findOneBy(['slug' => $slug]) !== null) {
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
