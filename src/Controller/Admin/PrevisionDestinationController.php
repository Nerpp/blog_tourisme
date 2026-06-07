<?php

namespace App\Controller\Admin;

use App\Entity\PrevisionDestination;
use App\Form\Admin\PrevisionDestinationType;
use App\Repository\PrevisionDestinationRepository;
use App\Security\Voter\AdminAccessVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class PrevisionDestinationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/previsions/destinations', name: 'admin_prevision_destinations_index', methods: ['GET'])]
    public function index(PrevisionDestinationRepository $previsionDestinationRepository): Response
    {
        return $this->render('admin/prevision_destinations/index.html.twig', [
            'prevision_destinations' => $previsionDestinationRepository->findForAdminIndex(),
            'status_labels' => $this->statusLabels(),
            'source_labels' => $this->sourceLabels(),
            'priority_labels' => $this->priorityLabels(),
        ]);
    }

    #[Route('/admin/previsions/destinations/new', name: 'admin_prevision_destinations_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $previsionDestination = (new PrevisionDestination())
            ->setSource($request->query->getString('source') === PrevisionDestination::SOURCE_GPS ? PrevisionDestination::SOURCE_GPS : PrevisionDestination::SOURCE_MANUAL);

        return $this->handleForm($request, $previsionDestination, 'Ajouter une destination', 'Ajouter la destination');
    }

    #[Route('/admin/previsions/destinations/{id}/edit', name: 'admin_prevision_destinations_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, PrevisionDestination $previsionDestination): Response
    {
        return $this->handleForm($request, $previsionDestination, 'Modifier la destination prévue', 'Enregistrer');
    }

    #[Route('/admin/previsions/destinations/{id}/delete', name: 'admin_prevision_destinations_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, PrevisionDestination $previsionDestination): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('prevision_destination_delete_'.$previsionDestination->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->entityManager->remove($previsionDestination);
        $this->entityManager->flush();
        $this->addFlash('success', 'Destination prévue supprimée.');

        return $this->redirectToRoute('admin_prevision_destinations_index');
    }

    private function handleForm(
        Request $request,
        PrevisionDestination $previsionDestination,
        string $title,
        string $submitLabel,
    ): Response {
        $form = $this->createForm(PrevisionDestinationType::class, $previsionDestination);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($previsionDestination);
            $this->entityManager->flush();
            $this->addFlash('success', 'Destination prévue enregistrée.');

            return $this->redirectToRoute('admin_prevision_destinations_index');
        }

        return $this->render('admin/prevision_destinations/form.html.twig', [
            'prevision_destination' => $previsionDestination,
            'form' => $form,
            'title' => $title,
            'submit_label' => $submitLabel,
        ]);
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            PrevisionDestination::STATUS_IDEA => 'Idée',
            PrevisionDestination::STATUS_TO_CHECK => 'À vérifier',
            PrevisionDestination::STATUS_TO_VISIT => 'À visiter',
            PrevisionDestination::STATUS_SPOTTED => 'Repérée',
            PrevisionDestination::STATUS_ABANDONED => 'Abandonnée',
        ];
    }

    /** @return array<string, string> */
    private function sourceLabels(): array
    {
        return [
            PrevisionDestination::SOURCE_MANUAL => 'Manuel',
            PrevisionDestination::SOURCE_SEARCH => 'Recherche',
            PrevisionDestination::SOURCE_GPS => 'GPS',
        ];
    }

    /** @return array<string, string> */
    private function priorityLabels(): array
    {
        return [
            PrevisionDestination::PRIORITY_LOW => 'Basse',
            PrevisionDestination::PRIORITY_MEDIUM => 'Moyenne',
            PrevisionDestination::PRIORITY_HIGH => 'Haute',
        ];
    }
}
