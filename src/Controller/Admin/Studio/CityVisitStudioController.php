<?php

namespace App\Controller\Admin\Studio;

use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPoint;
use App\Entity\CityVisitPointMedia;
use App\Entity\MediaAsset;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CityVisitPointType;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\DestinationRepository;
use App\Security\Voter\AdminAccessVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/studio')]
final class CityVisitStudioController extends AbstractController
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
    ) {
    }

    #[Route('/city-visits/{id}/edit', name: 'admin_studio_city_visit_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(CityVisitDraft $cityVisitDraft, Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('studio_city_visit_edit_'.$cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Le formulaire a expiré. Réessayez.');

                return $this->redirectToStudio($cityVisitDraft);
            }

            $this->updateDraftFromRequest($cityVisitDraft, $request);
            $this->entityManager->flush();
            $this->addFlash('success', 'La visite de ville rapide a été enregistrée.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        return $this->renderStudio($cityVisitDraft);
    }

    #[Route('/city-visits/{id}/media/photos', name: 'admin_studio_city_visit_media_photos', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadPhotos(CityVisitDraft $cityVisitDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_city_visit_photos_'.$cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire photo a expiré. Réessayez.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $createdCount = 0;
        $nextPosition = $this->nextMediaPosition($cityVisitDraft);
        $captions = $this->requestArray($request, 'photoCaptions');
        $imageTypes = $this->requestArray($request, 'photoImageTypes');
        $associations = $this->requestArray($request, 'photoAssociations');
        $pointMediaEnabled = $this->databaseTableExists('city_visit_point_media');
        foreach ($this->normalizeUploadedFiles($request->files->get('photos', [])) as $index => $file) {
            $association = (string) ($associations[$index] ?? self::MEDIA_ASSOCIATION_GALLERY);
            $targetPoint = $this->findPointFromAssociation($cityVisitDraft, $association);
            if ($targetPoint instanceof CityVisitPoint && !$pointMediaEnabled) {
                $this->addFlash('error', 'La liaison aux points GPS nécessite la migration des médias de points.');
                continue;
            }

            $media = $this->createImageAssetFromUpload(
                $file,
                (string) ($captions[$index] ?? ''),
                ImageType::tryFrom((string) ($imageTypes[$index] ?? '')) ?? ImageType::Standard,
            );
            if (!$media instanceof MediaAsset) {
                continue;
            }

            $this->entityManager->persist($media);
            if ($targetPoint instanceof CityVisitPoint) {
                $this->entityManager->persist((new CityVisitPointMedia())->setCityVisitPoint($targetPoint)->setMediaAsset($media));
            } else {
                $link = (new CityVisitDraftMedia())
                    ->setCityVisitDraft($cityVisitDraft)
                    ->setMediaAsset($media)
                    ->setRole($this->mediaRoleForPhotoAssociation($association))
                    ->setPosition($nextPosition);

                $this->entityManager->persist($link);
                ++$nextPosition;
            }
            ++$createdCount;
        }

        if ($createdCount === 0) {
            $this->addFlash('error', 'Aucune image n’a pu être ajoutée.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%d photo%s ajoutée%s.', $createdCount, $createdCount > 1 ? 's' : '', $createdCount > 1 ? 's' : ''));

        return $this->redirectToStudio($cityVisitDraft);
    }

    #[Route('/city-visits/{id}/media/video', name: 'admin_studio_city_visit_media_video', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addVideo(CityVisitDraft $cityVisitDraft, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_city_visit_video_'.$cityVisitDraft->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire vidéo a expiré. Réessayez.');

            return $this->redirectToStudio($cityVisitDraft);
        }

        $media = $this->createVideoAssetFromRequest($request);
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

        if (!$this->isCsrfTokenValid('studio_city_visit_media_update_'.$mediaLink->getId(), (string) $request->request->get('_token'))) {
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

        if (!$this->isCsrfTokenValid('studio_city_visit_media_delete_'.$mediaLink->getId(), (string) $request->request->get('_token'))) {
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
        $photoLinks = array_values(array_filter($generalMediaLinks, fn (CityVisitDraftMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Image));

        return $this->render('admin/studio/city_visit_edit.html.twig', [
            'city_visit' => $cityVisitDraft,
            'destinations' => $destinations,
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $destinations,
            'destination_quick_create' => $this->destinationQuickCreateData($cityVisitDraft),
            'google_maps_url' => $this->generateGoogleMapsUrl($cityVisitDraft),
            'media_links' => $generalMediaLinks,
            'photo_links' => $photoLinks,
            'cover_photo_links' => array_values(array_filter($photoLinks, static fn (CityVisitDraftMedia $link): bool => $link->getRole() === MediaRole::Cover)),
            'gallery_photo_links' => array_values(array_filter($photoLinks, static fn (CityVisitDraftMedia $link): bool => $link->getRole() !== MediaRole::Cover)),
            'video_links' => array_values(array_filter($generalMediaLinks, fn (CityVisitDraftMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Video)),
            'immersive_links' => array_values(array_filter($generalMediaLinks, $this->isImmersiveLink(...))),
            'status_options' => $this->enumChoices(CityVisitDraftStatus::cases(), [
                'draft' => 'Brouillon',
                'finished' => 'Terminé terrain',
                'converted' => 'Converti',
                'archived' => 'Archivé',
            ]),
            'image_type_options' => $this->imageTypeOptions(),
            'video_type_options' => $this->videoTypeOptions(),
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
        $cityVisitDraft
            ->setStatus(CityVisitDraftStatus::tryFrom($request->request->getString('status')) ?? $cityVisitDraft->getStatus())
            ->setDestination($destinationId !== null ? $this->destinationRepository->find($destinationId) : null)
            ->setNotes($this->nullIfBlank($request->request->getString('notes')));
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
            $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? $media->getVideoType() ?? VideoType::External;
            $media
                ->setVideoType($videoType === VideoType::Local ? VideoType::External : $videoType)
                ->setExternalUrl($this->nullIfBlank($request->request->getString('externalUrl')));
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

        $mediaLink->setRole(
            $media->getMediaType() === MediaType::Image
                ? $this->mediaRoleForPhotoAssociation($association)
                : MediaRole::Gallery
        );
    }

    /** @return list<CityVisitDraftMedia> */
    private function sortedMediaLinks(CityVisitDraft $cityVisitDraft): array
    {
        $mediaLinks = $cityVisitDraft->getMediaLinks()->toArray();
        usort($mediaLinks, static fn (CityVisitDraftMedia $a, CityVisitDraftMedia $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        return $mediaLinks;
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

        $coordinates = array_map(static fn (CityVisitPoint $point): string => $point->getLatitude().','.$point->getLongitude(), $points);
        $origin = array_shift($coordinates);
        $destination = array_pop($coordinates);
        $url = 'https://www.google.com/maps/dir/?api=1&travelmode=walking&origin='.rawurlencode((string) $origin).'&destination='.rawurlencode((string) $destination);

        if ($coordinates !== []) {
            $url .= '&waypoints='.rawurlencode(implode('|', $coordinates));
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
            'panorama' => 'Panorama',
            'wide_angle' => 'Grand angle',
        ]);
    }

    /** @return list<CityVisitPoint> */
    private function sortedPoints(CityVisitDraft $cityVisitDraft): array
    {
        $points = $cityVisitDraft->getPoints()->toArray();
        usort($points, static fn (CityVisitPoint $a, CityVisitPoint $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

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
            $options[self::MEDIA_ASSOCIATION_POINT_PREFIX.$pointId] = $label;
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

    private function mediaRoleForPhotoAssociation(mixed $association): MediaRole
    {
        return (string) $association === self::MEDIA_ASSOCIATION_MAIN
            ? MediaRole::Cover
            : MediaRole::Gallery;
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

    private function redirectToStudio(CityVisitDraft $cityVisitDraft): RedirectResponse
    {
        return $this->redirectToRoute('admin_studio_city_visit_edit', ['id' => $cityVisitDraft->getId()]);
    }
}
