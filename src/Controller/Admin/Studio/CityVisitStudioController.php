<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPoint;
use App\Entity\CityVisitPointMedia;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\DestinationRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\ImageUploadSecurity;
use App\Service\Media\BulkMediaUploadService;
use App\Service\Media\DronePanoramaUploadService;
use App\Service\Media\ImageTypeDetector;
use App\Service\Media\ImageMetadataSanitizer;
use App\Service\Media\MediaSeoTextService;
use App\Service\Media\MediaVariantService;
use App\Service\Media\MediaDeletionService;
use App\Service\Media\PublicMediaMasterCleanupService;
use App\Service\Media\VideoThumbnailGenerator;
use App\Service\Geography\LocationDraftHydrationException;
use App\Service\Geography\LocationDraftHydrator;
use App\Service\OrphanLocationCleanupService;
use App\Service\PublicationNotificationMailer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/studio')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class CityVisitStudioController extends AbstractController
{
    use StudioMediaHelperTrait;

    private const MEDIA_ASSOCIATION_MAIN = 'main';
    private const MEDIA_ASSOCIATION_GALLERY = 'gallery';
    private const MEDIA_ASSOCIATION_POINT_PREFIX = 'point:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DestinationRepository $destinationRepository,
        private readonly ParameterBagInterface $parameterBag,
        private readonly ImageUploadSecurity $imageUploadSecurity,
        private readonly DronePanoramaUploadService $panoramaUploadService,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
        private readonly ImageTypeDetector $imageTypeDetector,
        private readonly MediaSeoTextService $mediaSeoTextService,
        private readonly MediaVariantService $mediaVariantService,
        private readonly PublicMediaMasterCleanupService $publicMediaMasterCleanupService,
        private readonly BulkMediaUploadService $bulkMediaUploadService,
        private readonly VideoThumbnailGenerator $videoThumbnailGenerator,
        private readonly MediaDeletionService $mediaDeletionService,
        private readonly ActionRateLimiter $actionRateLimiter,
        private readonly PublicationNotificationMailer $publicationNotificationMailer,
        private readonly OrphanLocationCleanupService $orphanLocationCleanupService,
        private readonly LocationDraftHydrator $locationDraftHydrator,
    ) {}

    #[Route('/city-visits/{id}/edit', name: 'admin_studio_city_visit_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(CityVisitDraft $cityVisitDraft, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $cityVisitDraft);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('studio_city_visit_edit_' . $cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Le formulaire a expiré. Réessayez.');

                return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'section-publication');
            }

            $wasPublicStatus = $this->isPublicStatus($cityVisitDraft->getStatus());

            try {
                $this->updateDraftFromRequest($cityVisitDraft, $request);
            } catch (LocationDraftHydrationException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'section-publication');
            }

            $shouldNotifyPublication = !$wasPublicStatus && $this->isPublicStatus($cityVisitDraft->getStatus());

            $this->normalizeClassicCoverImages($cityVisitDraft->getMediaLinks());
            $this->entityManager->flush();
            $this->notifyNewPublication($cityVisitDraft, $shouldNotifyPublication);


            $this->addFlash('success', 'La visite de ville rapide a été enregistrée.');

            return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'section-publication');
        }

        return $this->renderStudio($cityVisitDraft);
    }

    #[Route('/city-visits/{id}/delete', name: 'admin_studio_city_visit_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(CityVisitDraft $cityVisitDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::DELETE, $cityVisitDraft);

        if (!$this->isCsrfTokenValid('studio_city_visit_delete_' . $cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La suppression n’a pas pu être validée. Réessayez.');

            return $this->redirectToRoute('admin_field_tools_city_visits');
        }

        $orphanCandidates = $this->cityVisitMediaCandidates($cityVisitDraft);
        $destinationCandidate = $cityVisitDraft->getDestination();
        $geographicDestinationCandidate = $cityVisitDraft->getGeographicDestination();

        foreach ($cityVisitDraft->getArticleLinks() as $articleLink) {
            $this->entityManager->remove($articleLink);
        }

        $this->entityManager->remove($cityVisitDraft);
        $this->entityManager->flush();

        foreach ($orphanCandidates as $media) {
            $this->mediaDeletionService->deleteIfOrphan($media);
        }
        $this->orphanLocationCleanupService->cleanupDestinationIfOrphan($destinationCandidate);
        $this->orphanLocationCleanupService->cleanupDestinationIfOrphan($geographicDestinationCandidate);
        $this->entityManager->flush();

        $this->addFlash('success', 'La visite de ville a bien été supprimée.');

        return $this->redirectToRoute('admin_field_tools_city_visits');
    }

    #[Route('/city-visits/{id}/media/photos', name: 'admin_studio_city_visit_media_photos', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[Route('/city-visits/{id}/media/photos/bulk-upload', name: 'admin_studio_city_visit_media_photos_bulk', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadPhotos(CityVisitDraft $cityVisitDraft, Request $request): RedirectResponse|JsonResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);
        $wantsJson = $this->wantsJsonUploadResponse($request);

        if (!$this->isCsrfTokenValid('studio_city_visit_photos_' . $cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
            if ($wantsJson) {
                return $this->uploadJsonResponse([
                    ['success' => false, 'error' => 'Le formulaire photo a expiré. Réessayez.'],
                ], 419);
            }

            $this->addFlash('error', 'Le formulaire photo a expiré. Réessayez.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        if (!$this->consumeUploadRateLimit($request)) {
            if ($wantsJson) {
                return $this->uploadJsonResponse([
                    ['success' => false, 'error' => 'Trop d’envois de médias. Réessayez plus tard.'],
                ], 429);
            }

            return $this->redirectToStudio($cityVisitDraft);
        }

        $createdCount = 0;
        $results = [];
        $createdMedia = [];
        $nextPosition = $this->nextMediaPosition($cityVisitDraft);
        $captions = $this->requestArray($request, 'photoCaptions');
        $imageTypes = $this->requestArray($request, 'photoImageTypes');
        $associations = $this->requestArray($request, 'photoAssociations');
        $pointMediaEnabled = $this->databaseTableExists('city_visit_point_media');
        $files = $this->normalizeUploadedFiles($request->files->get('photos', []));
        if (count($files) > BulkMediaUploadService::MAX_FILES_PER_SELECTION) {
            $results[] = ['success' => false, 'error' => sprintf('Sélection limitée à %d fichiers.', BulkMediaUploadService::MAX_FILES_PER_SELECTION)];
            if ($wantsJson) {
                return $this->uploadJsonResponse($results, 413);
            }

            $this->addFlash('error', $results[0]['error']);

            return $this->redirectToStudio($cityVisitDraft);
        }

        foreach ($files as $index => $file) {
            $association = (string) ($associations[$index] ?? self::MEDIA_ASSOCIATION_GALLERY);
            $targetPoint = $this->findPointFromAssociation($cityVisitDraft, $association);
            if ($targetPoint instanceof CityVisitPoint && !$pointMediaEnabled) {
                $results[] = $this->bulkMediaUploadService->errorPayload($file, 'La liaison aux points GPS nécessite la migration des médias de points.');
                $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');
                continue;
            }

            $error = null;
            $media = $this->createImageAssetFromUpload(
                $file,
                (string) ($captions[$index] ?? ''),
                ImageType::tryFrom((string) ($imageTypes[$index] ?? '')),
                $cityVisitDraft,
                $error,
            );
            if (!$media instanceof MediaAsset) {
                $results[] = $this->bulkMediaUploadService->errorPayload($file, $error ?? 'Image refusée.');
                continue;
            }

            $this->entityManager->persist($media);
            if ($targetPoint instanceof CityVisitPoint) {
                $this->entityManager->persist((new CityVisitPointMedia())->setCityVisitPoint($targetPoint)->setMediaAsset($media));
            } else {
                $link = (new CityVisitDraftMedia())
                    ->setMediaAsset($media)
                    ->setPosition($nextPosition);

                $cityVisitDraft->addMediaLink($link);
                if ((string) $association === self::MEDIA_ASSOCIATION_MAIN) {
                    $this->promoteClassicImageToCover($cityVisitDraft->getMediaLinks(), $link);
                } else {
                    $link->setRole(MediaRole::Gallery);
                }

                $this->entityManager->persist($link);
                ++$nextPosition;
            }
            ++$createdCount;
            $createdMedia[] = [$media, $file];
        }

        if ($createdCount === 0) {
            if ($wantsJson) {
                return $this->uploadJsonResponse($results !== [] ? $results : [
                    ['success' => false, 'error' => 'Aucune image n’a pu être ajoutée.'],
                ], 422);
            }

            $this->addFlash('error', 'Aucune image n’a pu être ajoutée.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $this->entityManager->flush();
        foreach ($createdMedia as [$media, $file]) {
            $results[] = $this->bulkMediaUploadService->successPayload($media, $file);
        }

        if ($wantsJson) {
            return $this->uploadJsonResponse($results, ($results[0]['success'] ?? false) ? 200 : 207);
        }

        $this->addFlash('success', sprintf('%d photo%s ajoutée%s.', $createdCount, $createdCount > 1 ? 's' : '', $createdCount > 1 ? 's' : ''));

        return $this->redirectToStudio($cityVisitDraft);
    }

    #[Route('/city-visits/{id}/media/video', name: 'admin_studio_city_visit_media_video', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addVideo(CityVisitDraft $cityVisitDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_city_visit_video_' . $cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire vidéo a expiré. Réessayez.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $media = $this->createVideoAssetFromRequest($request, $cityVisitDraft);
        if (!$media instanceof MediaAsset) {
            $this->addFlash('error', 'L’URL de la vidéo est obligatoire.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $pointMediaEnabled = $this->databaseTableExists('city_visit_point_media');
        $association = $request->request->getString('association', self::MEDIA_ASSOCIATION_GALLERY);
        $targetPoint = $this->findPointFromAssociation($cityVisitDraft, $association);
        if ($targetPoint instanceof CityVisitPoint && !$pointMediaEnabled) {
            $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $this->entityManager->persist($media);
        if ($targetPoint instanceof CityVisitPoint) {
            $this->entityManager->persist((new CityVisitPointMedia())->setCityVisitPoint($targetPoint)->setMediaAsset($media));
        } else {
            $link = (new CityVisitDraftMedia())
                ->setCityVisitDraft($cityVisitDraft)
                ->setMediaAsset($media)
                ->setRole(MediaRole::Gallery)
                ->setPosition($this->nextMediaPosition($cityVisitDraft));

            $this->entityManager->persist($link);
        }
        $this->entityManager->flush();
        $this->addFlash('success', 'La vidéo a été ajoutée à la visite.');

        return $this->redirectToStudio($cityVisitDraft);
    }

    #[Route('/city-visit-media/{id}/update', name: 'admin_studio_city_visit_media_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateMedia(CityVisitDraftMedia $mediaLink, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        $cityVisitDraft = $mediaLink->getCityVisitDraft();
        if (!$cityVisitDraft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Visite introuvable.');
        }

        if (!$this->isCsrfTokenValid('studio_city_visit_media_update_' . $mediaLink->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire média a expiré. Réessayez.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $this->updateMediaFromRequest($mediaLink, $request);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été mis à jour.');

        return $this->redirectToStudio($cityVisitDraft);
    }

    #[Route('/city-visit-media/{id}/delete', name: 'admin_studio_city_visit_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteMedia(CityVisitDraftMedia $mediaLink, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        $cityVisitDraft = $mediaLink->getCityVisitDraft();
        if (!$cityVisitDraft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Visite introuvable.');
        }

        if (!$this->isCsrfTokenValid('studio_city_visit_media_delete_' . $mediaLink->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La suppression a expiré. Réessayez.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $media = $mediaLink->getMediaAsset();
        if ($media instanceof MediaAsset && $this->databaseTableExists('city_visit_point_media')) {
            $this->syncPointMedia($cityVisitDraft, $media, null);
        }

        $this->entityManager->remove($mediaLink);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été retiré de cette fiche. Le fichier reste disponible dans la médiathèque.');

        return $this->redirectToStudio($cityVisitDraft);
    }

    #[Route('/city-visit-point-media/{id}/update', name: 'admin_studio_city_visit_point_media_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updatePointMedia(CityVisitPointMedia $pointMedia, Request $request): RedirectResponse
    {
        $point = $pointMedia->getCityVisitPoint();
        $cityVisitDraft = $point?->getCityVisitDraft();
        if (!$point instanceof CityVisitPoint || !$cityVisitDraft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Média de point introuvable.');
        }

        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $cityVisitDraft);

        if (!$this->isCsrfTokenValid('studio_city_visit_point_media_update_' . $pointMedia->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire média a expiré. Réessayez.');

            return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'city-visit-point-' . $point->getId());
        }

        $media = $pointMedia->getMediaAsset();
        if (!$media instanceof MediaAsset) {
            throw $this->createNotFoundException('Média introuvable.');
        }

        $this->updateSimpleMediaAssetFromRequest($media, $request);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média du point a été mis à jour.');

        return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'city-visit-point-' . $point->getId());
    }

    #[Route('/city-visit-point-media/{id}/delete', name: 'admin_studio_city_visit_point_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deletePointMedia(CityVisitPointMedia $pointMedia, Request $request): RedirectResponse
    {
        $point = $pointMedia->getCityVisitPoint();
        $cityVisitDraft = $point?->getCityVisitDraft();
        if (!$point instanceof CityVisitPoint || !$cityVisitDraft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Média de point introuvable.');
        }

        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $cityVisitDraft);

        if (!$this->isCsrfTokenValid('studio_city_visit_point_media_delete_' . $pointMedia->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le retrait a expiré. Réessayez.');

            return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'city-visit-point-' . $point->getId());
        }

        $point->removeMediaLink($pointMedia);
        $this->entityManager->remove($pointMedia);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été retiré du point.');

        return $this->redirectToStudioAfterRequest($cityVisitDraft, $request, 'city-visit-point-' . $point->getId());
    }

    private function renderStudio(CityVisitDraft $cityVisitDraft): Response
    {
        $mediaLinks = $this->sortedMediaLinks($cityVisitDraft);
        $pointMediaEnabled = $this->databaseTableExists('city_visit_point_media');
        $pointTargetOptions = $this->pointTargetOptions($cityVisitDraft);
        $mediaPointTargets = $pointMediaEnabled ? $this->mediaPointTargetMap($cityVisitDraft) : [];
        $destinations = $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']);
        $generalMediaLinks = array_values(array_filter($mediaLinks, static function (CityVisitDraftMedia $link) use ($mediaPointTargets): bool {
            $mediaId = $link->getMediaAsset()?->getId();

            return $mediaId === null || !isset($mediaPointTargets[$mediaId]);
        }));
        $photoLinks = array_values(array_filter($generalMediaLinks, fn(CityVisitDraftMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Image));
        $this->normalizeClassicCoverImages($photoLinks);

        return $this->render('admin/studio/city_visit_edit.html.twig', [
            'city_visit' => $cityVisitDraft,
            'destinations' => $destinations,
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $destinations,
            'destination_quick_create' => $this->destinationQuickCreateData($cityVisitDraft),
            'location_picker_data' => $this->locationPickerData($cityVisitDraft),
            'google_maps_url' => $this->generateGoogleMapsUrl($cityVisitDraft),
            'media_links' => $generalMediaLinks,
            'photo_links' => $photoLinks,
            'cover_photo_links' => array_values(array_filter($photoLinks, static fn(CityVisitDraftMedia $link): bool => $link->getRole() === MediaRole::Cover)),
            'gallery_photo_links' => array_values(array_filter($photoLinks, static fn(CityVisitDraftMedia $link): bool => $link->getRole() !== MediaRole::Cover)),
            'video_links' => array_values(array_filter($generalMediaLinks, fn(CityVisitDraftMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Video)),
            'immersive_links' => array_values(array_filter($generalMediaLinks, $this->isImmersiveLink(...))),
            'status_options' => $this->enumChoices(CityVisitDraftStatus::cases(), [
                'draft' => 'Brouillon',
                'finished' => 'Publié',
                'converted' => 'Converti',
                'archived' => 'Archivé',
            ]),
            'image_type_options' => $this->imageTypeOptions(),
            'video_type_options' => $this->videoTypeOptions(),
            'bulk_upload_policy' => $this->bulkMediaUploadService->clientPolicy(),
            'photo_association_options' => $this->photoAssociationOptions($pointTargetOptions),
            'video_association_options' => $this->videoAssociationOptions($pointTargetOptions),
            'point_target_options' => $pointTargetOptions,
            'point_labels' => $pointTargetOptions,
            'point_media_enabled' => $pointMediaEnabled,
            'media_point_targets' => $mediaPointTargets,
        ]);
    }

    private function updateDraftFromRequest(CityVisitDraft $cityVisitDraft, Request $request): void
    {
        $title = $this->truncate($request->request->getString('title'), 180);
        if ($title !== '') {
            $cityVisitDraft->setTitle($title);
        }

        $destinationId = $this->nullableInt($request->request->get('destination'));
        $hasSelectedCommune = $this->requestHasSelectedCommune($request);
        $notes = $this->nullIfBlank($request->request->getString('notes'));
        $status = CityVisitDraftStatus::tryFrom($request->request->getString('status')) ?? $cityVisitDraft->getStatus();

        $cityVisitDraft
            ->setStatus($status)
            ->setNotes($notes);

        if ($hasSelectedCommune) {
            $this->locationDraftHydrator->hydrateCityVisitDraft(
                $cityVisitDraft,
                $this->locationDraftHydrator->dataFromRequest($request),
            );

            $geographicDestination = $cityVisitDraft->getGeographicDestination();
            if ($geographicDestination instanceof Destination) {
                $cityVisitDraft->setDestination($geographicDestination);
            }
        } else {
            $cityVisitDraft->setDestination($destinationId !== null ? $this->destinationRepository->find($destinationId) : null);
        }

        if ($this->isPublicStatus($status) && $cityVisitDraft->getFinishedAt() === null) {
            $cityVisitDraft->setFinishedAt(new DateTimeImmutable());
        }

        $destination = $cityVisitDraft->getDestination();
        if ($destination instanceof Destination) {
            $destination->setDescription($notes);
        }
    }

    private function requestHasSelectedCommune(Request $request): bool
    {
        $commune = trim($request->request->getString('detectedCommuneName'));
        $insee = trim($request->request->getString('detectedCommuneCode'));

        return $commune !== '' && $insee !== '';
    }

    private function isPublicStatus(CityVisitDraftStatus $status): bool
    {
        return in_array($status, [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted], true);
    }

    private function notifyNewPublication(CityVisitDraft $cityVisitDraft, bool $shouldNotify): void
    {
        if (!$shouldNotify) {
            return;
        }

        $report = $this->publicationNotificationMailer->sendNewPublicationNotification($cityVisitDraft);
        if ($report['errorCount'] > 0) {
            $this->addFlash('warning', 'La publication a été enregistrée, mais l’envoi des notifications a rencontré une erreur.');
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
    private function destinationQuickCreateData(CityVisitDraft $cityVisitDraft): array
    {
        $point = $this->latestPoint($cityVisitDraft);
        $cityName = $cityVisitDraft->getDetectedCommuneName() ?? '';

        return [
            'contextType' => 'city_visit',
            'contextId' => $cityVisitDraft->getId(),
            'targetType' => 'city_visit',
            'targetId' => $cityVisitDraft->getId(),
            'name' => $cityName,
            'countryName' => $cityName !== '' ? 'France' : '',
            'regionName' => $cityVisitDraft->getDetectedRegionName() ?? '',
            'departmentName' => $cityVisitDraft->getDetectedDepartmentName() ?? '',
            'cityName' => $cityName,
            'parent' => null,
            'type' => $cityName !== '' ? DestinationType::City->value : DestinationType::Area->value,
            'code' => $cityVisitDraft->getDetectedCommuneCode() ?? '',
            'latitude' => $point?->getLatitude(),
            'longitude' => $point?->getLongitude(),
        ];
    }

    /** @return array<string, float|string|null> */
    private function locationPickerData(CityVisitDraft $cityVisitDraft): array
    {
        $point = $this->primaryLocationPoint($cityVisitDraft);
        $geographicDestination = $cityVisitDraft->getGeographicDestination();

        return [
            'country' => 'France',
            'commune' => $cityVisitDraft->getDetectedCommuneName(),
            'insee' => $cityVisitDraft->getDetectedCommuneCode(),
            'postalCode' => null,
            'department' => $cityVisitDraft->getDetectedDepartmentName(),
            'departmentCode' => $this->departmentCodeFromDestination($geographicDestination),
            'region' => $cityVisitDraft->getDetectedRegionName(),
            'communeCenterLatitude' => $geographicDestination?->getLatitude(),
            'communeCenterLongitude' => $geographicDestination?->getLongitude(),
            'latitude' => $point?->getLatitude(),
            'longitude' => $point?->getLongitude(),
            'accuracy' => $point?->getAccuracy(),
        ];
    }

    private function primaryLocationPoint(CityVisitDraft $cityVisitDraft): ?CityVisitPoint
    {
        $points = $this->sortedPoints($cityVisitDraft);
        foreach ($points as $point) {
            if ($point->getType() === CityVisitPointType::Start) {
                return $point;
            }
        }

        return $points[0] ?? null;
    }

    private function departmentCodeFromDestination(?Destination $destination): ?string
    {
        while ($destination instanceof Destination) {
            if ($destination->getType() === DestinationType::Department) {
                return $destination->getCode();
            }

            $destination = $destination->getParent();
        }

        return null;
    }

    private function latestPoint(CityVisitDraft $cityVisitDraft): ?CityVisitPoint
    {
        $points = $this->sortedPoints($cityVisitDraft);

        return $points === [] ? null : $points[array_key_last($points)];
    }

    private function updateMediaFromRequest(CityVisitDraftMedia $mediaLink, Request $request): void
    {
        $media = $mediaLink->getMediaAsset();
        if (!$media instanceof MediaAsset) {
            throw $this->createNotFoundException('Média introuvable.');
        }

        $media
            ->setTitle($this->nullIfBlank($request->request->getString('title')))
            ->setAltText($this->nullIfBlank($request->request->getString('altText')))
            ->setCaption($this->nullIfBlank($request->request->getString('caption')));

        if ($media->getMediaType() === MediaType::Image) {
            $media->setImageType(ImageType::tryFrom($request->request->getString('imageType')) ?? ImageType::Standard);
        }

        if ($media->getMediaType() === MediaType::Video) {
            $previousExternalUrl = $media->getExternalUrl();
            $externalUrl = $this->nullIfBlank($request->request->getString('externalUrl'));
            $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? $media->getVideoType() ?? VideoType::External;
            $media
                ->setVideoType($videoType === VideoType::Local ? VideoType::External : $videoType)
                ->setExternalUrl($externalUrl);
            if ($externalUrl !== $previousExternalUrl) {
                $media->setThumbnailPath(null);
            }
            if ($media->getThumbnailPath() === null || $media->getThumbnailPath() === '') {
                $this->videoThumbnailGenerator->generateForMedia($media);
            }
        }

        $cityVisitDraft = $mediaLink->getCityVisitDraft();
        if (!$cityVisitDraft instanceof CityVisitDraft) {
            throw $this->createNotFoundException('Visite introuvable.');
        }

        $association = $request->request->getString('association', self::MEDIA_ASSOCIATION_GALLERY);
        if ($this->databaseTableExists('city_visit_point_media')) {
            $targetPoint = $this->findPointFromAssociation($cityVisitDraft, $association);
            $this->syncPointMedia($cityVisitDraft, $media, $targetPoint);
            if ($targetPoint instanceof CityVisitPoint) {
                $this->entityManager->remove($mediaLink);

                return;
            }
        } elseif ($this->isPointAssociation($association)) {
            $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');
        }

        if ($media->getMediaType() === MediaType::Image && (string) $association === self::MEDIA_ASSOCIATION_MAIN) {
            $this->promoteClassicImageToCover($cityVisitDraft->getMediaLinks(), $mediaLink);

            return;
        }

        $mediaLink->setRole(MediaRole::Gallery);
    }

    /** @return list<CityVisitDraftMedia> */
    private function sortedMediaLinks(CityVisitDraft $cityVisitDraft): array
    {
        $mediaLinks = $cityVisitDraft->getMediaLinks()->toArray();
        usort($mediaLinks, static fn(CityVisitDraftMedia $a, CityVisitDraftMedia $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        return $mediaLinks;
    }

    /** @return list<MediaAsset> */
    private function cityVisitMediaCandidates(CityVisitDraft $cityVisitDraft): array
    {
        $candidates = [];

        foreach ($cityVisitDraft->getMediaLinks() as $mediaLink) {
            $media = $mediaLink->getMediaAsset();
            if ($media instanceof MediaAsset) {
                $candidates[$media->getId() ?? spl_object_id($media)] = $media;
            }
        }

        foreach ($cityVisitDraft->getPoints() as $point) {
            foreach ($point->getMediaLinks() as $pointMediaLink) {
                $media = $pointMediaLink->getMediaAsset();
                if ($media instanceof MediaAsset) {
                    $candidates[$media->getId() ?? spl_object_id($media)] = $media;
                }
            }
        }

        return array_values($candidates);
    }

    private function isImmersiveLink(CityVisitDraftMedia $mediaLink): bool
    {
        $media = $mediaLink->getMediaAsset();

        return $media instanceof MediaAsset
            && $media->getMediaType() === MediaType::Image
            && in_array($media->getImageType(), [ImageType::Degree360, ImageType::Degree180], true);
    }

    private function nextMediaPosition(CityVisitDraft $cityVisitDraft): int
    {
        $maxPosition = -1;
        foreach ($cityVisitDraft->getMediaLinks() as $mediaLink) {
            $maxPosition = max($maxPosition, $mediaLink->getPosition());
        }

        return $maxPosition + 1;
    }

    private function generateGoogleMapsUrl(CityVisitDraft $cityVisitDraft): ?string
    {
        $points = $this->sortedPoints($cityVisitDraft);

        if (count($points) < 2) {
            return null;
        }

        $coordinates = array_map(static fn(CityVisitPoint $point): string => $point->getLatitude() . ',' . $point->getLongitude(), $points);
        $origin = array_shift($coordinates);
        $destination = array_pop($coordinates);
        $url = 'https://www.google.com/maps/dir/?api=1&travelmode=walking&origin=' . rawurlencode((string) $origin) . '&destination=' . rawurlencode((string) $destination);

        if ($coordinates !== []) {
            $url .= '&waypoints=' . rawurlencode(implode('|', $coordinates));
        }

        return $url;
    }

    /** @return array<string, string> */
    private function imageTypeOptions(): array
    {
        return $this->enumChoices(ImageType::cases(), [
            'standard' => 'Image classique',
            '360' => 'Image 360°',
            '180' => 'Image 180°',
            'panorama' => 'Image panoramique',
            'wide_angle' => 'Grand angle',
        ]);
    }

    /** @return list<CityVisitPoint> */
    private function sortedPoints(CityVisitDraft $cityVisitDraft): array
    {
        $points = $cityVisitDraft->getPoints()->toArray();
        usort($points, static fn(CityVisitPoint $a, CityVisitPoint $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        return $points;
    }

    /** @return array<int, string> */
    private function pointTargetOptions(CityVisitDraft $cityVisitDraft): array
    {
        $options = [];
        foreach ($this->sortedPoints($cityVisitDraft) as $point) {
            if ($point->getId() === null) {
                continue;
            }

            $options[$point->getId()] = $this->pointLabel($point);
        }

        return $options;
    }

    /**
     * @param array<int, string> $pointTargetOptions
     *
     * @return array<string, string>
     */
    private function photoAssociationOptions(array $pointTargetOptions): array
    {
        return [
            self::MEDIA_ASSOCIATION_MAIN => 'Image principale',
            self::MEDIA_ASSOCIATION_GALLERY => 'Galerie générale',
        ] + $this->pointAssociationOptions($pointTargetOptions);
    }

    /**
     * @param array<int, string> $pointTargetOptions
     *
     * @return array<string, string>
     */
    private function videoAssociationOptions(array $pointTargetOptions): array
    {
        return [
            self::MEDIA_ASSOCIATION_GALLERY => 'Galerie générale',
        ] + $this->pointAssociationOptions($pointTargetOptions);
    }

    /**
     * @param array<int, string> $pointTargetOptions
     *
     * @return array<string, string>
     */
    private function pointAssociationOptions(array $pointTargetOptions): array
    {
        $options = [];
        foreach ($pointTargetOptions as $pointId => $label) {
            $options[self::MEDIA_ASSOCIATION_POINT_PREFIX . $pointId] = $label;
        }

        return $options;
    }

    /** @return array<int, int> */
    private function mediaPointTargetMap(CityVisitDraft $cityVisitDraft): array
    {
        $map = [];
        foreach ($this->sortedPoints($cityVisitDraft) as $point) {
            if ($point->getId() === null) {
                continue;
            }

            foreach ($point->getMediaLinks() as $pointMedia) {
                $mediaId = $pointMedia->getMediaAsset()?->getId();
                if ($mediaId !== null) {
                    $map[$mediaId] = $point->getId();
                }
            }
        }

        return $map;
    }

    private function findPointFromAssociation(CityVisitDraft $cityVisitDraft, mixed $association): ?CityVisitPoint
    {
        $pointId = $this->pointIdFromAssociation($association);

        return $pointId !== null ? $this->findPointById($cityVisitDraft, $pointId) : null;
    }

    private function pointIdFromAssociation(mixed $association): ?int
    {
        $association = trim((string) $association);
        if (!str_starts_with($association, self::MEDIA_ASSOCIATION_POINT_PREFIX)) {
            return null;
        }

        return $this->nullableInt(substr($association, strlen(self::MEDIA_ASSOCIATION_POINT_PREFIX)));
    }

    private function isPointAssociation(mixed $association): bool
    {
        return str_starts_with(trim((string) $association), self::MEDIA_ASSOCIATION_POINT_PREFIX);
    }

    private function findPointById(CityVisitDraft $cityVisitDraft, mixed $pointId): ?CityVisitPoint
    {
        $pointId = $this->nullableInt($pointId);
        if ($pointId === null) {
            return null;
        }

        foreach ($cityVisitDraft->getPoints() as $point) {
            if ($point->getId() === $pointId) {
                return $point;
            }
        }

        return null;
    }

    private function syncPointMedia(CityVisitDraft $cityVisitDraft, MediaAsset $media, ?CityVisitPoint $targetPoint): void
    {
        $existingTarget = false;
        foreach ($this->sortedPoints($cityVisitDraft) as $point) {
            foreach ($point->getMediaLinks()->toArray() as $pointMedia) {
                $linkedMedia = $pointMedia->getMediaAsset();
                $isSameMedia = $linkedMedia === $media
                    || ($linkedMedia?->getId() !== null && $media->getId() !== null && $linkedMedia->getId() === $media->getId());
                if (!$isSameMedia) {
                    continue;
                }

                if ($targetPoint instanceof CityVisitPoint && $point === $targetPoint) {
                    $existingTarget = true;
                    continue;
                }

                $point->removeMediaLink($pointMedia);
                $this->entityManager->remove($pointMedia);
            }
        }

        if ($targetPoint instanceof CityVisitPoint && !$existingTarget) {
            $pointMedia = (new CityVisitPointMedia())
                ->setCityVisitPoint($targetPoint)
                ->setMediaAsset($media);
            $targetPoint->addMediaLink($pointMedia);
            $this->entityManager->persist($pointMedia);
        }
    }

    private function pointLabel(CityVisitPoint $point): string
    {
        $typeLabel = match ($point->getType()) {
            CityVisitPointType::Start => 'Départ',
            CityVisitPointType::Monument => 'Monument',
            CityVisitPointType::Viewpoint => 'Point de vue',
            CityVisitPointType::Museum => 'Musée',
            CityVisitPointType::Church => 'Église',
            CityVisitPointType::Square => 'Place',
            CityVisitPointType::Restaurant => 'Restaurant',
            CityVisitPointType::Photo => 'Spot photo',
            CityVisitPointType::Parking => 'Parking',
            CityVisitPointType::End => 'Arrivée',
            CityVisitPointType::Other => 'Autre point',
        };
        $title = $this->nullIfBlank($point->getTitle());
        $label = $title ?? $typeLabel;

        return sprintf('Point %d — %s', $point->getPosition(), $label);
    }

    /** @return array<string, string> */
    private function videoTypeOptions(): array
    {
        return $this->enumChoices([VideoType::Youtube, VideoType::Vimeo, VideoType::Dailymotion, VideoType::External], [
            'youtube' => 'YouTube',
            'vimeo' => 'Vimeo',
            'dailymotion' => 'Dailymotion',
            'external' => 'Externe',
        ]);
    }

    private function redirectToStudio(CityVisitDraft $cityVisitDraft, ?string $anchor = null): RedirectResponse
    {
        $parameters = ['id' => $cityVisitDraft->getId()];
        if ($this->isSafeRedirectAnchor($anchor)) {
            $parameters['_fragment'] = $anchor;
        }

        return $this->redirectToRoute('admin_studio_city_visit_edit', $parameters);
    }

    private function redirectToStudioAfterRequest(CityVisitDraft $cityVisitDraft, Request $request, ?string $fallbackAnchor = null): RedirectResponse
    {
        return $this->redirectToStudio($cityVisitDraft, $this->redirectAnchorFromRequest($request) ?? $fallbackAnchor);
    }

    private function redirectAnchorFromRequest(Request $request): ?string
    {
        $anchor = $request->request->get('_redirect_anchor');

        return is_string($anchor) && $this->isSafeRedirectAnchor($anchor) ? $anchor : null;
    }

    private function isSafeRedirectAnchor(?string $anchor): bool
    {
        return is_string($anchor) && preg_match('/^[a-zA-Z0-9_-]+$/', $anchor) === 1;
    }
}
