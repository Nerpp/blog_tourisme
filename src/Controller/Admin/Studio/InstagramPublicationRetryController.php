<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\InstagramPublication;
use App\Enum\InstagramPublicationSourceType;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\Instagram\InstagramPublicationScheduler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/studio')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class InstagramPublicationRetryController extends AbstractController
{
    public function __construct(
        private readonly HikeDraftRepository $hikeDraftRepository,
        private readonly CityVisitDraftRepository $cityVisitDraftRepository,
        private readonly InstagramPublicationScheduler $publicationScheduler,
    ) {
    }

    #[Route(
        '/instagram-publications/{id}/retry',
        name: 'admin_studio_instagram_retry',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function __invoke(InstagramPublication $instagramPublication, Request $request): RedirectResponse
    {
        $content = $this->sourceContent($instagramPublication);
        if (!$content instanceof HikeDraft && !$content instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Le contenu associé à cette publication Instagram n’existe plus.');
        }

        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $content);
        $redirect = $this->studioUrl($content);

        if (!$this->isCsrfTokenValid(
            'instagram_publication_retry_' . $instagramPublication->getId(),
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('error', 'La demande de renvoi Instagram a expiré. Réessayez.');

            return $this->redirect($redirect);
        }

        if (!$content->isPublished()) {
            $this->addFlash('error', 'Le contenu doit être publié sur Estela avant un renvoi Instagram.');

            return $this->redirect($redirect);
        }

        if (!$instagramPublication->canRetry()) {
            $this->addFlash('warning', 'Cette publication Instagram n’a aucun élément manquant à renvoyer.');

            return $this->redirect($redirect);
        }

        if ($this->publicationScheduler->retry((int) $instagramPublication->getId())) {
            $this->addFlash('success', 'Le renvoi des éléments Instagram manquants a été programmé.');
        } else {
            $this->addFlash('warning', 'La demande est enregistrée et sera reprise automatiquement dès que la file sera disponible.');
        }

        return $this->redirect($redirect);
    }

    private function sourceContent(InstagramPublication $publication): HikeDraft|CityVisitDraft|null
    {
        return match ($publication->getSourceType()) {
            InstagramPublicationSourceType::Hike => $this->hikeDraftRepository->find($publication->getSourceId()),
            InstagramPublicationSourceType::CityVisit => $this->cityVisitDraftRepository->find($publication->getSourceId()),
        };
    }

    private function studioUrl(HikeDraft|CityVisitDraft $content): string
    {
        $route = $content instanceof HikeDraft
            ? 'admin_studio_hike_edit'
            : 'admin_studio_city_visit_edit';

        return $this->generateUrl($route, ['id' => $content->getId()]) . '#section-instagram';
    }
}
