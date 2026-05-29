<?php

namespace App\Controller\Admin\Studio;

use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePoint;
use App\Entity\HikePointMedia;
use App\Entity\MediaAsset;
use App\Enum\DestinationType;
use App\Enum\HikeDraftStatus;
use App\Enum\HikePointType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\DestinationRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\ImageUploadSecurity;
use App\Service\Media\DronePanoramaUploadService;
use App\Service\Media\BulkMediaUploadService;
use App\Service\Media\ImageTypeDetector;
use App\Service\Media\ImageMetadataSanitizer;
use App\Service\Media\MediaSeoTextService;
use App\Service\Media\MediaVariantService;
use App\Service\Media\VideoThumbnailGenerator;
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
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/studio')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class HikeStudioController extends AbstractController
{
    use StudioMediaHelperTrait;

    private const MEDIA_ASSOCIATION_MAIN = 'main';
    private const MEDIA_ASSOCIATION_GALLERY = 'gallery';
    private const MEDIA_ASSOCIATION_POINT_PREFIX = 'point:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DestinationRepository $destinationRepository,
        private readonly SluggerInterface $slugger,
        private readonly ParameterBagInterface $parameterBag,
        private readonly ImageUploadSecurity $imageUploadSecurity,
        private readonly DronePanoramaUploadService $panoramaUploadService,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
        private readonly ImageTypeDetector $imageTypeDetector,
        private readonly MediaSeoTextService $mediaSeoTextService,
        private readonly MediaVariantService $mediaVariantService,
        private readonly BulkMediaUploadService $bulkMediaUploadService,
        private readonly VideoThumbnailGenerator $videoThumbnailGenerator,
        private readonly ActionRateLimiter $actionRateLimiter,
        private readonly PublicationNotificationMailer $publicationNotificationMailer,
    ) {}

    #[Route('/hikes/{id}/edit', name: 'admin_studio_hike_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(HikeDraft $hikeDraft, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $hikeDraft);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('studio_hike_edit_' . $hikeDraft->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Le formulaire a expiré. Réessayez.');

                return $this->redirectToStudio($hikeDraft);
            }

            $wasPublicStatus = $this->isPublicStatus($hikeDraft->getStatus());

            $this->updateDraftFromRequest($hikeDraft, $request);

            $shouldNotifyPublication = !$wasPublicStatus && $this->isPublicStatus($hikeDraft->getStatus());

            $this->entityManager->flush();
            $this->notifyNewPublication($hikeDraft, $shouldNotifyPublication);

            $this->addFlash('success', 'La randonnée rapide a été enregistrée.');

            return $this->redirectToStudio($hikeDraft);
        }

        return $this->renderStudio($hikeDraft);
    }

    #[Route('/hikes/{id}/delete', name: 'admin_studio_hike_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(HikeDraft $hikeDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::DELETE, $hikeDraft);

        if (!$this->isCsrfTokenValid('studio_hike_delete_' . $hikeDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La suppression n’a pas pu être validée. Réessayez.');

            return $this->redirectToRoute('admin_field_tools_hikes');
        }

        foreach ($hikeDraft->getArticleLinks() as $articleLink) {
            $this->entityManager->remove($articleLink);
        }

        $this->entityManager->remove($hikeDraft);
        $this->entityManager->flush();
        $this->addFlash('success', 'La randonnée a bien été supprimée.');

        return $this->redirectToRoute('admin_field_tools_hikes');
    }

    #[Route('/hikes/{id}/media/photos', name: 'admin_studio_hike_media_photos', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[Route('/hikes/{id}/media/photos/bulk-upload', name: 'admin_studio_hike_media_photos_bulk', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadPhotos(HikeDraft $hikeDraft, Request $request): RedirectResponse|JsonResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);
        $wantsJson = $this->wantsJsonUploadResponse($request);

        if (!$this->isCsrfTokenValid('studio_hike_photos_' . $hikeDraft->getId(), (string) $request->request->get('_token'))) {
            if ($wantsJson) {
                return $this->uploadJsonResponse([
                    ['success' => false, 'error' => 'Le formulaire photo a expiré. Réessayez.'],
                ], 419);
            }

            $this->addFlash('error', 'Le formulaire photo a expiré. Réessayez.');

            return $this->redirectToStudio($hikeDraft);
        }

        if (!$this->consumeUploadRateLimit($request)) {
            if ($wantsJson) {
                return $this->uploadJsonResponse([
                    ['success' => false, 'error' => 'Trop d’envois de médias. Réessayez plus tard.'],
                ], 429);
            }

            return $this->redirectToStudio($hikeDraft);
        }

        $createdCount = 0;
        $results = [];
        $createdMedia = [];
        $nextPosition = $this->nextMediaPosition($hikeDraft);
        $captions = $this->requestArray($request, 'photoCaptions');
        $imageTypes = $this->requestArray($request, 'photoImageTypes');
        $associations = $this->requestArray($request, 'photoAssociations');
        $pointMediaEnabled = $this->databaseTableExists('hike_point_media');
        $files = $this->normalizeUploadedFiles($request->files->get('photos', []));
        if (count($files) > BulkMediaUploadService::MAX_FILES_PER_SELECTION) {
            $results[] = ['success' => false, 'error' => sprintf('Sélection limitée à %d fichiers.', BulkMediaUploadService::MAX_FILES_PER_SELECTION)];
            if ($wantsJson) {
                return $this->uploadJsonResponse($results, 413);
            }

            $this->addFlash('error', $results[0]['error']);

            return $this->redirectToStudio($hikeDraft);
        }

        foreach ($files as $index => $file) {
            $association = (string) ($associations[$index] ?? self::MEDIA_ASSOCIATION_GALLERY);
            $targetPoint = $this->findPointFromAssociation($hikeDraft, $association);
            if ($targetPoint instanceof HikePoint && !$pointMediaEnabled) {
                $results[] = $this->bulkMediaUploadService->errorPayload($file, 'La liaison aux points GPS nécessite la migration des médias de points.');
                $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');
                continue;
            }

            $error = null;
            $media = $this->createImageAssetFromUpload(
                $file,
                (string) ($captions[$index] ?? ''),
                ImageType::tryFrom((string) ($imageTypes[$index] ?? '')),
                $hikeDraft,
                $error,
            );
            if (!$media instanceof MediaAsset) {
                $results[] = $this->bulkMediaUploadService->errorPayload($file, $error ?? 'Image refusée.');
                continue;
            }

            $this->entityManager->persist($media);
            if ($targetPoint instanceof HikePoint) {
                $this->entityManager->persist((new HikePointMedia())->setHikePoint($targetPoint)->setMediaAsset($media));
            } else {
                $link = (new HikeDraftMedia())
                    ->setHikeDraft($hikeDraft)
                    ->setMediaAsset($media)
                    ->setRole($this->mediaRoleForPhotoAssociation($association))
                    ->setPosition($nextPosition);

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

            return $this->redirectToStudio($hikeDraft);
        }

        $this->entityManager->flush();
        foreach ($createdMedia as [$media, $file]) {
            $results[] = $this->bulkMediaUploadService->successPayload($media, $file);
        }

        if ($wantsJson) {
            return $this->uploadJsonResponse($results, ($results[0]['success'] ?? false) ? 200 : 207);
        }

        $this->addFlash('success', sprintf('%d photo%s ajoutée%s.', $createdCount, $createdCount > 1 ? 's' : '', $createdCount > 1 ? 's' : ''));

        return $this->redirectToStudio($hikeDraft);
    }

    #[Route('/hikes/{id}/media/video', name: 'admin_studio_hike_media_video', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addVideo(HikeDraft $hikeDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_hike_video_' . $hikeDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire vidéo a expiré. Réessayez.');

            return $this->redirectToStudio($hikeDraft);
        }

        $media = $this->createVideoAssetFromRequest($request, $hikeDraft);
        if (!$media instanceof MediaAsset) {
            $this->addFlash('error', 'L’URL de la vidéo est obligatoire.');

            return $this->redirectToStudio($hikeDraft);
        }

        $pointMediaEnabled = $this->databaseTableExists('hike_point_media');
        $association = $request->request->getString('association', self::MEDIA_ASSOCIATION_GALLERY);
        $targetPoint = $this->findPointFromAssociation($hikeDraft, $association);
        if ($targetPoint instanceof HikePoint && !$pointMediaEnabled) {
            $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');

            return $this->redirectToStudio($hikeDraft);
        }

        $this->entityManager->persist($media);
        if ($targetPoint instanceof HikePoint) {
            $this->entityManager->persist((new HikePointMedia())->setHikePoint($targetPoint)->setMediaAsset($media));
        } else {
            $link = (new HikeDraftMedia())
                ->setHikeDraft($hikeDraft)
                ->setMediaAsset($media)
                ->setRole(MediaRole::Gallery)
                ->setPosition($this->nextMediaPosition($hikeDraft));

            $this->entityManager->persist($link);
        }
        $this->entityManager->flush();
        $this->addFlash('success', 'La vidéo a été ajoutée à la randonnée.');

        return $this->redirectToStudio($hikeDraft);
    }

    #[Route('/hike-points/{id}/update', name: 'admin_studio_hike_point_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updatePoint(HikePoint $point, Request $request): RedirectResponse
    {
        $hikeDraft = $point->getHikeDraft();
        if (!$hikeDraft instanceof HikeDraft) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $hikeDraft);

        if (!$this->isCsrfTokenValid('studio_hike_point_update_' . $point->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire du point GPS a expiré. Réessayez.');

            return $this->redirectToStudio($hikeDraft);
        }

        $latitude = $this->coordinateFromRequest($request, 'latitude', -90, 90, 'La latitude GPS');
        $longitude = $this->coordinateFromRequest($request, 'longitude', -180, 180, 'La longitude GPS');
        if ($latitude === null || $longitude === null) {
            return $this->redirectToStudio($hikeDraft);
        }

        $position = $this->nullableInt($request->request->get('position'));
        if ($position !== null && $position < 1) {
            $this->addFlash('error', 'La position du point doit être supérieure ou égale à 1.');

            return $this->redirectToStudio($hikeDraft);
        }

        $point
            ->setTitle($this->nullIfBlank($request->request->getString('title')))
            ->setNote($this->nullIfBlank($request->request->getString('note')))
            ->setLatitude($latitude)
            ->setLongitude($longitude);

        $type = HikePointType::tryFrom($request->request->getString('type'));
        if ($type instanceof HikePointType) {
            $point->setType($type);
        }

        if ($position !== null) {
            $point->setPosition($position);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'Point GPS enregistré.');

        return $this->redirectToStudio($hikeDraft);
    }

    #[Route('/hikes/{id}/destination/update', name: 'admin_studio_hike_destination_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateDestination(HikeDraft $hikeDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $hikeDraft);

        if (!$this->isCsrfTokenValid('studio_hike_destination_update_' . $hikeDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire de destination a expiré. Réessayez.');

            return $this->redirectToStudio($hikeDraft);
        }

        $destination = $hikeDraft->getDestination();
        if (!$destination instanceof Destination) {
            $this->addFlash('error', 'Aucune destination n’est associée à cette randonnée.');

            return $this->redirectToStudio($hikeDraft);
        }

        $type = DestinationType::tryFrom($request->request->getString('type')) ?? $destination->getType();
        $name = $this->destinationNameFromRequest($request, $type);
        if ($name === '') {
            $this->addFlash('error', 'Renseignez le nom correspondant au type de destination.');

            return $this->redirectToStudio($hikeDraft);
        }

        $latitude = $this->nullableCoordinateFromRequest($request, 'latitude', -90, 90, 'La latitude GPS');
        $longitude = $this->nullableCoordinateFromRequest($request, 'longitude', -180, 180, 'La longitude GPS');
        if ($latitude === false || $longitude === false) {
            return $this->redirectToStudio($hikeDraft);
        }

        $destination
            ->setName($name)
            ->setType($type)
            ->setParent($this->parentForDestinationEdit($request, $type, $destination))
            ->setCode($this->codeForType($type, $this->nullIfBlank($request->request->getString('code'))))
            ->setLatitude($latitude)
            ->setLongitude($longitude);

        $this->entityManager->flush();
        $this->addFlash('success', 'Destination associée enregistrée.');

        return $this->redirectToStudio($hikeDraft);
    }

    #[Route('/hike-media/{id}/update', name: 'admin_studio_hike_media_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateMedia(HikeDraftMedia $mediaLink, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        $hikeDraft = $mediaLink->getHikeDraft();
        if (!$hikeDraft instanceof HikeDraft) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        if (!$this->isCsrfTokenValid('studio_hike_media_update_' . $mediaLink->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire média a expiré. Réessayez.');

            return $this->redirectToStudio($hikeDraft);
        }

        $this->updateMediaFromRequest($mediaLink, $request);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été mis à jour.');

        return $this->redirectToStudio($hikeDraft);
    }

    #[Route('/hike-media/{id}/delete', name: 'admin_studio_hike_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteMedia(HikeDraftMedia $mediaLink, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        $hikeDraft = $mediaLink->getHikeDraft();
        if (!$hikeDraft instanceof HikeDraft) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        if (!$this->isCsrfTokenValid('studio_hike_media_delete_' . $mediaLink->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La suppression a expiré. Réessayez.');

            return $this->redirectToStudio($hikeDraft);
        }

        $media = $mediaLink->getMediaAsset();
        if ($media instanceof MediaAsset && $this->databaseTableExists('hike_point_media')) {
            $this->syncPointMedia($hikeDraft, $media, null);
        }

        $this->entityManager->remove($mediaLink);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été retiré de cette fiche. Le fichier reste disponible dans la médiathèque.');

        return $this->redirectToStudio($hikeDraft);
    }

    private function renderStudio(HikeDraft $hikeDraft): Response
    {
        $mediaLinks = $this->sortedMediaLinks($hikeDraft);
        $pointMediaEnabled = $this->databaseTableExists('hike_point_media');
        $pointTargetOptions = $this->pointTargetOptions($hikeDraft);
        $mediaPointTargets = $pointMediaEnabled ? $this->mediaPointTargetMap($hikeDraft) : [];
        $destinations = $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']);
        $destinationLocationBadges = $this->destinationLocationBadgesById($destinations);
        $generalMediaLinks = array_values(array_filter($mediaLinks, static function (HikeDraftMedia $link) use ($mediaPointTargets): bool {
            $mediaId = $link->getMediaAsset()?->getId();

            return $mediaId === null || !isset($mediaPointTargets[$mediaId]);
        }));
        $photoLinks = array_values(array_filter($generalMediaLinks, fn(HikeDraftMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Image));

        return $this->render('admin/studio/hike_edit.html.twig', [
            'hike' => $hikeDraft,
            'destinations' => $destinations,
            'location_badges' => $this->hikeLocationBadges($hikeDraft),
            'destination_location_badges' => $destinationLocationBadges,
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $destinations,
            'destination_quick_create' => $this->destinationQuickCreateData($hikeDraft),
            'current_destination_edit' => $this->currentDestinationEditData($hikeDraft),
            'google_maps_url' => $this->generateGoogleMapsUrl($hikeDraft),
            'media_links' => $generalMediaLinks,
            'photo_links' => $photoLinks,
            'cover_photo_links' => array_values(array_filter($photoLinks, static fn(HikeDraftMedia $link): bool => $link->getRole() === MediaRole::Cover)),
            'gallery_photo_links' => array_values(array_filter($photoLinks, static fn(HikeDraftMedia $link): bool => $link->getRole() !== MediaRole::Cover)),
            'video_links' => array_values(array_filter($generalMediaLinks, fn(HikeDraftMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Video)),
            'immersive_links' => array_values(array_filter($generalMediaLinks, $this->isImmersiveLink(...))),
            'status_options' => $this->enumChoices(HikeDraftStatus::cases(), [
                'draft' => 'Brouillon',
                'finished' => 'Publié',
                'converted' => 'Converti',
                'archived' => 'Archivé',
            ]),
            'point_type_options' => $this->pointTypeOptions(),
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

    private function updateDraftFromRequest(HikeDraft $hikeDraft, Request $request): void
    {
        $previousTitle = $hikeDraft->getTitle();
        $title = $this->truncate($request->request->getString('title'), 180);
        if ($title !== '' && ($title !== $previousTitle || $this->shouldRefreshHikeSlug($hikeDraft, $title))) {
            $hikeDraft->setTitle($title);
            $hikeDraft->setSlug($this->createUniqueHikeSlug($title, $hikeDraft));
        }

        $destinationId = $this->nullableInt($request->request->get('destination'));
        $status = HikeDraftStatus::tryFrom($request->request->getString('status')) ?? $hikeDraft->getStatus();
        $hikeDraft
            ->setStatus($status)
            ->setDestination($destinationId !== null ? $this->destinationRepository->find($destinationId) : null)
            ->setNotes($this->nullIfBlank($request->request->getString('notes')));

        if ($this->isPublicStatus($status) && $hikeDraft->getFinishedAt() === null) {
            $hikeDraft->setFinishedAt(new DateTimeImmutable());
        }
    }

    private function isPublicStatus(HikeDraftStatus $status): bool
    {
        return in_array($status, [HikeDraftStatus::Finished, HikeDraftStatus::Converted], true);
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

    /**
     * @param list<Destination> $destinations
     *
     * @return array<int, list<string>>
     */
    private function destinationLocationBadgesById(array $destinations): array
    {
        $badgesById = [];
        foreach ($destinations as $destination) {
            $id = $destination->getId();
            if ($id !== null) {
                $badgesById[$id] = $this->destinationLocationBadges($destination);
            }
        }

        return $badgesById;
    }

    /** @return list<string> */
    private function hikeLocationBadges(HikeDraft $hikeDraft): array
    {
        $destination = $hikeDraft->getDestination();
        if ($destination instanceof Destination) {
            return $this->destinationLocationBadges($destination);
        }

        return array_values(array_filter([
            $hikeDraft->getDetectedCommuneName(),
            $hikeDraft->getDetectedDepartmentName(),
            $hikeDraft->getDetectedRegionName(),
        ], static fn(?string $value): bool => $value !== null && $value !== ''));
    }

    /** @return list<string> */
    private function destinationLocationBadges(Destination $destination): array
    {
        $badges = [];
        $current = $destination;

        while ($current instanceof Destination) {
            $name = $current->getName();
            if ($name !== null && $name !== '') {
                $badges[] = $name;
            }

            $current = $current->getParent();
        }

        return $badges;
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

    private function coordinateFromRequest(Request $request, string $field, float $min, float $max, string $label): ?float
    {
        $rawValue = trim((string) $request->request->get($field, ''));
        if ($rawValue === '') {
            $this->addFlash('error', sprintf('%s est obligatoire pour conserver un point GPS valide.', $label));

            return null;
        }

        $normalizedValue = str_replace(',', '.', $rawValue);
        if (!is_numeric($normalizedValue)) {
            $this->addFlash('error', sprintf('%s doit être un nombre valide.', $label));

            return null;
        }

        $coordinate = (float) $normalizedValue;
        if ($coordinate < $min || $coordinate > $max) {
            $this->addFlash('error', sprintf('%s doit être comprise entre %s et %s.', $label, $min, $max));

            return null;
        }

        return $coordinate;
    }

    private function nullableCoordinateFromRequest(Request $request, string $field, float $min, float $max, string $label): float|false|null
    {
        $rawValue = trim((string) $request->request->get($field, ''));
        if ($rawValue === '') {
            return null;
        }

        $normalizedValue = str_replace(',', '.', $rawValue);
        if (!is_numeric($normalizedValue)) {
            $this->addFlash('error', sprintf('%s doit être un nombre valide.', $label));

            return false;
        }

        $coordinate = (float) $normalizedValue;
        if ($coordinate < $min || $coordinate > $max) {
            $this->addFlash('error', sprintf('%s doit être comprise entre %s et %s.', $label, $min, $max));

            return false;
        }

        return $coordinate;
    }

    private function destinationNameFromRequest(Request $request, DestinationType $type): string
    {
        $countryName = $this->truncate($request->request->getString('countryName'), 150);
        $regionName = $this->truncate($request->request->getString('regionName'), 150);
        $departmentName = $this->truncate($request->request->getString('departmentName'), 150);
        $cityName = $this->truncate($request->request->getString('cityName'), 150);
        $areaName = $this->truncate($request->request->getString('areaName'), 150);

        return match ($type) {
            DestinationType::Country => $countryName,
            DestinationType::Region => $regionName,
            DestinationType::Department => $departmentName,
            DestinationType::City => $cityName,
            DestinationType::Area => $areaName ?: $cityName,
        };
    }

    private function parentForDestinationEdit(Request $request, DestinationType $type, Destination $currentDestination): ?Destination
    {
        $countryName = $this->truncate($request->request->getString('countryName'), 150);
        $regionName = $this->truncate($request->request->getString('regionName'), 150);
        $departmentName = $this->truncate($request->request->getString('departmentName'), 150);

        $parent = null;
        if ($type !== DestinationType::Country && $countryName !== '') {
            $parent = $this->findOrCreateDestinationParent($countryName, DestinationType::Country, null, $currentDestination);
        }

        if (in_array($type, [DestinationType::Department, DestinationType::City, DestinationType::Area], true) && $regionName !== '') {
            $parent = $this->findOrCreateDestinationParent($regionName, DestinationType::Region, $parent, $currentDestination);
        }

        if (in_array($type, [DestinationType::City, DestinationType::Area], true) && $departmentName !== '') {
            $parent = $this->findOrCreateDestinationParent($departmentName, DestinationType::Department, $parent, $currentDestination);
        }

        return $parent;
    }

    private function findOrCreateDestinationParent(string $name, DestinationType $type, ?Destination $parent, Destination $currentDestination): ?Destination
    {
        $destination = $this->destinationRepository->findOneBy(['name' => $name, 'type' => $type]);
        if ($this->sameDestination($destination, $currentDestination)) {
            return $parent;
        }

        if (!$destination instanceof Destination) {
            $destination = (new Destination())
                ->setName($name)
                ->setSlug($this->createUniqueDestinationSlug($name))
                ->setType($type);
            $this->entityManager->persist($destination);
        }

        if (!$this->sameDestination($destination->getParent(), $parent)) {
            $destination->setParent($parent);
        }

        return $destination;
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

    private function sameDestination(?Destination $first, ?Destination $second): bool
    {
        if (!$first instanceof Destination || !$second instanceof Destination) {
            return $first === $second;
        }

        return $first === $second || ($first->getId() !== null && $first->getId() === $second->getId());
    }

    private function createUniqueDestinationSlug(string $name): string
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

    private function createUniqueHikeSlug(string $title, HikeDraft $currentHike): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($title));
        $baseSlug = trim($baseSlug, '-') ?: 'randonnee';
        $slug = $baseSlug;
        $suffix = 2;
        $repository = $this->entityManager->getRepository(HikeDraft::class);

        while (($existing = $repository->findOneBy(['slug' => $slug])) instanceof HikeDraft && $existing->getId() !== $currentHike->getId()) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }

    private function shouldRefreshHikeSlug(HikeDraft $hikeDraft, string $title): bool
    {
        $currentSlug = $hikeDraft->getSlug();
        if ($currentSlug === null || $currentSlug === '') {
            return true;
        }

        $expectedBaseSlug = trim(strtolower((string) $this->slugger->slug($title)), '-') ?: 'randonnee';
        if ($currentSlug === $expectedBaseSlug || preg_match('/^' . preg_quote($expectedBaseSlug, '/') . '-\d+$/', $currentSlug) === 1) {
            return false;
        }

        return preg_match('/^randonnee-du-\d{2}-\d{2}-\d{4}/', $currentSlug) === 1;
    }

    /** @return array<string, float|int|string|null> */
    private function destinationQuickCreateData(HikeDraft $hikeDraft): array
    {
        $point = $this->latestPoint($hikeDraft);
        $cityName = $hikeDraft->getDetectedCommuneName() ?? '';

        return [
            'contextType' => 'hike',
            'contextId' => $hikeDraft->getId(),
            'targetType' => 'hike',
            'targetId' => $hikeDraft->getId(),
            'name' => $cityName,
            'countryName' => $cityName !== '' ? 'France' : '',
            'regionName' => $hikeDraft->getDetectedRegionName() ?? '',
            'departmentName' => $hikeDraft->getDetectedDepartmentName() ?? '',
            'cityName' => $cityName,
            'parent' => null,
            'type' => $cityName !== '' ? DestinationType::City->value : DestinationType::Area->value,
            'code' => $hikeDraft->getDetectedCommuneCode() ?? '',
            'latitude' => $point?->getLatitude(),
            'longitude' => $point?->getLongitude(),
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function currentDestinationEditData(HikeDraft $hikeDraft): array
    {
        $destination = $hikeDraft->getDestination();
        if (!$destination instanceof Destination) {
            return [];
        }

        $data = [
            'countryName' => '',
            'regionName' => '',
            'departmentName' => '',
            'cityName' => '',
            'areaName' => '',
            'type' => $destination->getType()->value,
            'code' => $destination->getCode(),
            'latitude' => $destination->getLatitude(),
            'longitude' => $destination->getLongitude(),
        ];

        $current = $destination;
        while ($current instanceof Destination) {
            match ($current->getType()) {
                DestinationType::Country => $data['countryName'] = $current->getName() ?? '',
                DestinationType::Region => $data['regionName'] = $current->getName() ?? '',
                DestinationType::Department => $data['departmentName'] = $current->getName() ?? '',
                DestinationType::City => $data['cityName'] = $current->getName() ?? '',
                DestinationType::Area => $data['areaName'] = $current->getName() ?? '',
            };

            $current = $current->getParent();
        }

        if ($destination->getType() === DestinationType::Area && $data['areaName'] === '') {
            $data['areaName'] = $destination->getName() ?? '';
        }

        return $data;
    }

    private function latestPoint(HikeDraft $hikeDraft): ?HikePoint
    {
        $points = $this->sortedPoints($hikeDraft);

        return $points === [] ? null : $points[array_key_last($points)];
    }

    private function updateMediaFromRequest(HikeDraftMedia $mediaLink, Request $request): void
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
            $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? $media->getVideoType() ?? VideoType::External;
            $media
                ->setVideoType($videoType === VideoType::Local ? VideoType::External : $videoType)
                ->setExternalUrl($this->nullIfBlank($request->request->getString('externalUrl')));
            if ($media->getThumbnailPath() === null || $media->getThumbnailPath() === '') {
                $this->videoThumbnailGenerator->generateForMedia($media);
            }
        }

        $hikeDraft = $mediaLink->getHikeDraft();
        if (!$hikeDraft instanceof HikeDraft) {
            throw $this->createNotFoundException('Randonnée introuvable.');
        }

        $association = $request->request->getString('association', self::MEDIA_ASSOCIATION_GALLERY);
        if ($this->databaseTableExists('hike_point_media')) {
            $targetPoint = $this->findPointFromAssociation($hikeDraft, $association);
            $this->syncPointMedia($hikeDraft, $media, $targetPoint);
            if ($targetPoint instanceof HikePoint) {
                $this->entityManager->remove($mediaLink);

                return;
            }
        } elseif ($this->isPointAssociation($association)) {
            $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');
        }

        $mediaLink->setRole(
            $media->getMediaType() === MediaType::Image
                ? $this->mediaRoleForPhotoAssociation($association)
                : MediaRole::Gallery
        );
    }

    /** @return list<HikeDraftMedia> */
    private function sortedMediaLinks(HikeDraft $hikeDraft): array
    {
        $mediaLinks = $hikeDraft->getMediaLinks()->toArray();
        usort($mediaLinks, static fn(HikeDraftMedia $a, HikeDraftMedia $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        return $mediaLinks;
    }

    private function isImmersiveLink(HikeDraftMedia $mediaLink): bool
    {
        $media = $mediaLink->getMediaAsset();

        return $media instanceof MediaAsset
            && $media->getMediaType() === MediaType::Image
            && in_array($media->getImageType(), [ImageType::Degree360, ImageType::Degree180], true);
    }

    private function nextMediaPosition(HikeDraft $hikeDraft): int
    {
        $maxPosition = -1;
        foreach ($hikeDraft->getMediaLinks() as $mediaLink) {
            $maxPosition = max($maxPosition, $mediaLink->getPosition());
        }

        return $maxPosition + 1;
    }

    private function generateGoogleMapsUrl(HikeDraft $hikeDraft): ?string
    {
        $points = $this->sortedPoints($hikeDraft);

        if ($points === []) {
            return null;
        }

        $coordinates = array_map(static fn(HikePoint $point): string => $point->getLatitude() . ',' . $point->getLongitude(), $points);
        if (count($coordinates) === 1) {
            return 'https://www.google.com/maps/search/?api=1&query=' . $coordinates[0];
        }

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

    /** @return list<HikePoint> */
    private function sortedPoints(HikeDraft $hikeDraft): array
    {
        $points = $hikeDraft->getPoints()->toArray();
        usort($points, static fn(HikePoint $a, HikePoint $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        return $points;
    }

    /** @return array<int, string> */
    private function pointTargetOptions(HikeDraft $hikeDraft): array
    {
        $options = [];
        foreach ($this->sortedPoints($hikeDraft) as $point) {
            if ($point->getId() === null) {
                continue;
            }

            $options[$point->getId()] = $this->pointLabel($point);
        }

        return $options;
    }

    /** @param array<int, string> $pointTargetOptions */
    private function photoAssociationOptions(array $pointTargetOptions): array
    {
        return [
            self::MEDIA_ASSOCIATION_MAIN => 'Image principale',
            self::MEDIA_ASSOCIATION_GALLERY => 'Galerie générale',
        ] + $this->pointAssociationOptions($pointTargetOptions);
    }

    /** @param array<int, string> $pointTargetOptions */
    private function videoAssociationOptions(array $pointTargetOptions): array
    {
        return [
            self::MEDIA_ASSOCIATION_GALLERY => 'Galerie générale',
        ] + $this->pointAssociationOptions($pointTargetOptions);
    }

    /** @param array<int, string> $pointTargetOptions */
    private function pointAssociationOptions(array $pointTargetOptions): array
    {
        $options = [];
        foreach ($pointTargetOptions as $pointId => $label) {
            $options[self::MEDIA_ASSOCIATION_POINT_PREFIX . $pointId] = $label;
        }

        return $options;
    }

    /** @return array<int, int> */
    private function mediaPointTargetMap(HikeDraft $hikeDraft): array
    {
        $map = [];
        foreach ($this->sortedPoints($hikeDraft) as $point) {
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

    private function findPointFromAssociation(HikeDraft $hikeDraft, mixed $association): ?HikePoint
    {
        $pointId = $this->pointIdFromAssociation($association);

        return $pointId !== null ? $this->findPointById($hikeDraft, $pointId) : null;
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

    private function mediaRoleForPhotoAssociation(mixed $association): MediaRole
    {
        return (string) $association === self::MEDIA_ASSOCIATION_MAIN
            ? MediaRole::Cover
            : MediaRole::Gallery;
    }

    private function findPointById(HikeDraft $hikeDraft, mixed $pointId): ?HikePoint
    {
        $pointId = $this->nullableInt($pointId);
        if ($pointId === null) {
            return null;
        }

        foreach ($hikeDraft->getPoints() as $point) {
            if ($point->getId() === $pointId) {
                return $point;
            }
        }

        return null;
    }

    private function syncPointMedia(HikeDraft $hikeDraft, MediaAsset $media, ?HikePoint $targetPoint): void
    {
        $existingTarget = false;
        foreach ($this->sortedPoints($hikeDraft) as $point) {
            foreach ($point->getMediaLinks()->toArray() as $pointMedia) {
                $linkedMedia = $pointMedia->getMediaAsset();
                $isSameMedia = $linkedMedia === $media
                    || ($linkedMedia?->getId() !== null && $media->getId() !== null && $linkedMedia->getId() === $media->getId());
                if (!$isSameMedia) {
                    continue;
                }

                if ($targetPoint instanceof HikePoint && $point === $targetPoint) {
                    $existingTarget = true;
                    continue;
                }

                $point->removeMediaLink($pointMedia);
                $this->entityManager->remove($pointMedia);
            }
        }

        if ($targetPoint instanceof HikePoint && !$existingTarget) {
            $pointMedia = (new HikePointMedia())
                ->setHikePoint($targetPoint)
                ->setMediaAsset($media);
            $targetPoint->addMediaLink($pointMedia);
            $this->entityManager->persist($pointMedia);
        }
    }

    private function pointLabel(HikePoint $point): string
    {
        $typeLabel = match ($point->getType()) {
            HikePointType::Start => 'Départ',
            HikePointType::Interest => 'Point d’intérêt',
            HikePointType::Viewpoint => 'Point de vue',
            HikePointType::Photo => 'Spot photo',
            HikePointType::Water => 'Point d’eau',
            HikePointType::Danger => 'Zone de vigilance',
            HikePointType::Rest => 'Pause',
            HikePointType::End => 'Arrivée',
            HikePointType::Other => 'Autre point',
        };
        $title = $this->nullIfBlank($point->getTitle());
        $label = $title ?? $typeLabel;

        return sprintf('Point %d — %s', $point->getPosition(), $label);
    }

    /** @return array<string, string> */
    private function pointTypeOptions(): array
    {
        return $this->enumChoices(HikePointType::cases(), [
            'start' => 'Départ',
            'interest' => 'Point d’intérêt',
            'viewpoint' => 'Point de vue',
            'photo' => 'Spot photo',
            'water' => 'Point d’eau',
            'danger' => 'Zone de vigilance',
            'rest' => 'Pause',
            'end' => 'Arrivée',
            'other' => 'Autre point',
        ]);
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

    private function redirectToStudio(HikeDraft $hikeDraft): RedirectResponse
    {
        return $this->redirectToRoute('admin_studio_hike_edit', ['id' => $hikeDraft->getId()]);
    }
}
