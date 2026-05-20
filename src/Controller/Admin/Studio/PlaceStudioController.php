<?php

namespace App\Controller\Admin\Studio;

use App\Entity\Category;
use App\Entity\Destination;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use App\Enum\VideoType;
use App\Repository\CategoryRepository;
use App\Repository\DestinationRepository;
use App\Security\ActionRateLimiter;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\ImageUploadSecurity;
use App\Service\Media\DronePanoramaUploadService;
use App\Service\Media\ImageMetadataSanitizer;
use App\Service\Media\MediaSeoTextService;
use App\Service\Media\MediaVariantService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/studio')]
#[IsGranted(AdminAccessVoter::ACCESS)]
final class PlaceStudioController extends AbstractController
{
    private const UPLOAD_DIRECTORY = 'uploads/media';
    private const MEDIA_USAGE_MAIN = 'main';
    private const MEDIA_USAGE_GALLERY = 'gallery';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly DestinationRepository $destinationRepository,
        private readonly SluggerInterface $slugger,
        private readonly ParameterBagInterface $parameterBag,
        private readonly ImageUploadSecurity $imageUploadSecurity,
        private readonly DronePanoramaUploadService $panoramaUploadService,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
        private readonly MediaSeoTextService $mediaSeoTextService,
        private readonly MediaVariantService $mediaVariantService,
        private readonly ActionRateLimiter $actionRateLimiter,
    ) {
    }

    #[Route('/places/{id}/edit', name: 'admin_studio_place_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Place $place, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $place);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('studio_place_edit_'.$place->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Le formulaire a expiré. Réessayez.');

                return $this->redirectToStudio($place);
            }

            $this->updatePlaceFromRequest($place, $request);
            $this->entityManager->flush();

            $this->addFlash('success', 'Le repérage a été enregistré.');

            return $this->redirectToStudio($place);
        }

        return $this->renderStudio($place);
    }

    #[Route('/places/{id}/media/photos', name: 'admin_studio_place_media_photos', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadPhotos(Place $place, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_place_photos_'.$place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire photo a expiré. Réessayez.');

            return $this->redirectToStudio($place);
        }

        if (!$this->consumeUploadRateLimit($request)) {
            return $this->redirectToStudio($place);
        }

        $files = $this->normalizeUploadedFiles($request->files->get('photos', []));
        if ($files === []) {
            $this->addFlash('error', 'Aucune photo valide n’a été reçue.');

            return $this->redirectToStudio($place);
        }

        $createdCount = 0;
        $nextPosition = $this->nextMediaPosition($place);
        $captions = $this->requestArray($request, 'photoCaptions');
        $imageTypes = $this->requestArray($request, 'photoImageTypes');
        $associations = $this->requestArray($request, 'photoAssociations');
        if ($associations === []) {
            $associations = $this->requestArray($request, 'photoUsages');
        }
        foreach ($files as $index => $file) {
            $imageType = ImageType::tryFrom((string) ($imageTypes[$index] ?? '')) ?? ImageType::Standard;
            try {
                $storedFile = $this->storeImageByType($file, $imageType, $place);
            } catch (InvalidArgumentException $exception) {
                $this->addFlash('warning', sprintf('Image "%s" ignorée : %s', $file->getClientOriginalName(), $exception->getMessage()));

                continue;
            }

            $role = $this->mediaRoleForUsage($this->resolvePhotoUsage($associations[$index] ?? null));
            $position = $nextPosition;
            $media = (new MediaAsset())
                ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
                ->setTitle($this->truncate($storedFile['title'], 180))
                ->setAltText($storedFile['altText'] ?? null)
                ->setCaption($this->nullIfBlank((string) ($captions[$index] ?? '')))
                ->setMediaType(MediaType::Image)
                ->setImageType($imageType)
                ->setFilePath($storedFile['path'])
                ->setThumbnailPath($storedFile['thumbnailPath'] ?? null)
                ->setMimeType($storedFile['mimeType'])
                ->setFileSize($storedFile['fileSize'])
                ->setWidth($storedFile['width'])
                ->setHeight($storedFile['height'])
                ->setProjection($storedFile['projection'] ?? null)
                ->setMetadata($storedFile['metadata'] ?? null);

            $variantResult = $this->mediaVariantService->generateForMedia($media);
            if ($variantResult['status'] === 'error') {
                $this->addFlash('warning', sprintf('Image "%s" ajoutée, mais variantes responsive non générées.', $file->getClientOriginalName()));
            }

            $placeMedia = (new PlaceMedia())
                ->setPlace($place)
                ->setMediaAsset($media)
                ->setRole($role)
                ->setPosition($position);

            $this->entityManager->persist($media);
            $this->entityManager->persist($placeMedia);
            if ($role === MediaRole::Cover) {
                $place->setFeaturedImage($media);
            }
            $nextPosition = max($nextPosition, $position + 1);
            ++$createdCount;
        }

        if ($createdCount === 0) {
            $this->addFlash('error', 'Aucune image n’a pu être ajoutée.');

            return $this->redirectToStudio($place);
        }

        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%d photo%s ajoutée%s.', $createdCount, $createdCount > 1 ? 's' : '', $createdCount > 1 ? 's' : ''));

        return $this->redirectToStudio($place);
    }

    #[Route('/places/{id}/media/video', name: 'admin_studio_place_media_video', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addVideo(Place $place, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        if (!$this->isCsrfTokenValid('studio_place_video_'.$place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire vidéo a expiré. Réessayez.');

            return $this->redirectToStudio($place);
        }

        $externalUrl = $this->nullIfBlank($request->request->getString('externalUrl'));
        if ($externalUrl === null) {
            $this->addFlash('error', 'L’URL de la vidéo est obligatoire.');

            return $this->redirectToStudio($place);
        }

        $videoType = VideoType::tryFrom($request->request->getString('videoType')) ?? VideoType::External;
        if ($videoType === VideoType::Local) {
            $videoType = VideoType::External;
        }

        $role = $this->mediaRoleForUsage($this->resolveVideoUsage($request->request->get('usage')));

        $media = (new MediaAsset())
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setTitle(
                $this->nullIfBlank($request->request->getString('title'))
                ?? $this->mediaSeoTextService->titleForContext($place, MediaType::Video),
            )
            ->setAltText($this->mediaSeoTextService->altTextForContext($place, MediaType::Video))
            ->setCaption($this->nullIfBlank($request->request->getString('caption')))
            ->setMediaType(MediaType::Video)
            ->setVideoType($videoType)
            ->setExternalUrl($externalUrl);

        $placeMedia = (new PlaceMedia())
            ->setPlace($place)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($this->nextMediaPosition($place));

        $this->entityManager->persist($media);
        $this->entityManager->persist($placeMedia);
        $this->entityManager->flush();

        $this->addFlash('success', 'La vidéo a été ajoutée au repérage.');

        return $this->redirectToStudio($place);
    }

    #[Route('/place-media/{id}/update', name: 'admin_studio_place_media_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updatePlaceMedia(PlaceMedia $placeMedia, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        $place = $placeMedia->getPlace();
        if (!$place instanceof Place) {
            throw $this->createNotFoundException('Repérage introuvable.');
        }

        if (!$this->isCsrfTokenValid('studio_place_media_update_'.$placeMedia->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire média a expiré. Réessayez.');

            return $this->redirectToStudio($place);
        }

        $media = $placeMedia->getMediaAsset();
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
            if ($videoType === VideoType::Local) {
                $videoType = VideoType::External;
            }

            $media
                ->setVideoType($videoType)
                ->setExternalUrl($this->nullIfBlank($request->request->getString('externalUrl')));
        }

        $usage = $media->getMediaType() === MediaType::Image
            ? $this->resolvePhotoUsage($request->request->get('usage'))
            : $this->resolveVideoUsage($request->request->get('usage'));
        $role = $this->mediaRoleForUsage($usage);
        $placeMedia->setRole($role);

        if ($role === MediaRole::Cover) {
            $place->setFeaturedImage($media);
        } elseif ($place->getFeaturedImage() === $media) {
            $place->setFeaturedImage(null);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été mis à jour.');

        return $this->redirectToStudio($place);
    }

    #[Route('/place-media/{id}/delete', name: 'admin_studio_place_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deletePlaceMedia(PlaceMedia $placeMedia, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminAccessVoter::ACCESS);

        $place = $placeMedia->getPlace();
        if (!$place instanceof Place) {
            throw $this->createNotFoundException('Repérage introuvable.');
        }

        if (!$this->isCsrfTokenValid('studio_place_media_delete_'.$placeMedia->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La suppression a expiré. Réessayez.');

            return $this->redirectToStudio($place);
        }

        $media = $placeMedia->getMediaAsset();
        if ($media instanceof MediaAsset && $place->getFeaturedImage() === $media) {
            $place->setFeaturedImage(null);
        }

        $this->entityManager->remove($placeMedia);
        $this->entityManager->flush();
        $this->addFlash('success', 'Le média a été retiré de cette fiche. Le fichier reste disponible dans la médiathèque.');

        return $this->redirectToStudio($place);
    }

    private function renderStudio(Place $place): Response
    {
        $mediaLinks = $this->sortedMediaLinks($place);
        $destinations = $this->destinationRepository->findBy([], ['type' => 'ASC', 'name' => 'ASC']);

        return $this->render('admin/studio/place_edit.html.twig', [
            'place' => $place,
            'categories' => $this->categoryRepository->findBy([], ['name' => 'ASC']),
            'destinations' => $destinations,
            'destination_type_options' => $this->destinationTypeOptions(),
            'destination_parent_options' => $destinations,
            'destination_quick_create' => $this->destinationQuickCreateData($place),
            'media_links' => $mediaLinks,
            'photo_links' => array_values(array_filter($mediaLinks, fn (PlaceMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Image)),
            'video_links' => array_values(array_filter($mediaLinks, fn (PlaceMedia $link): bool => $link->getMediaAsset()?->getMediaType() === MediaType::Video)),
            'immersive_links' => array_values(array_filter($mediaLinks, $this->isImmersiveLink(...))),
            'status_options' => $this->enumChoices(ContentStatus::cases(), [
                'draft' => 'Brouillon',
                'published' => 'Publié',
                'private' => 'Privé',
                'archived' => 'Archivé',
            ]),
            'difficulty_options' => $this->enumChoices(PlaceDifficulty::cases(), [
                'easy' => 'Facile',
                'medium' => 'Moyenne',
                'hard' => 'Difficile',
                'unknown' => 'Non précisée',
            ]),
            'price_type_options' => $this->enumChoices(PriceType::cases(), [
                'free' => 'Gratuit',
                'paid' => 'Payant',
                'mixed' => 'Mixte',
                'unknown' => 'Non renseigné',
            ]),
            'image_type_options' => $this->enumChoices(ImageType::cases(), [
                'standard' => 'Image classique',
                '360' => 'Image 360°',
                '180' => 'Image 180°',
                'panorama' => 'Image panoramique',
                'wide_angle' => 'Grand angle',
            ]),
            'photo_usage_options' => $this->photoUsageOptions(),
            'video_type_options' => $this->enumChoices([VideoType::Youtube, VideoType::Vimeo, VideoType::Dailymotion, VideoType::External], [
                'youtube' => 'YouTube',
                'vimeo' => 'Vimeo',
                'dailymotion' => 'Dailymotion',
                'external' => 'Externe',
            ]),
            'video_usage_options' => $this->videoUsageOptions(),
        ]);
    }

    private function updatePlaceFromRequest(Place $place, Request $request): void
    {
        $name = $this->truncate($request->request->getString('name'), 180);
        if ($name !== '') {
            $place->setName($name);
        }

        $slug = $this->truncate($request->request->getString('slug'), 180);
        if ($slug === '') {
            $slug = strtolower((string) $this->slugger->slug($place->getName() ?? 'reperage'));
        }
        $place->setSlug($slug);

        $destination = $this->findRequestedDestination($request);
        if ($destination instanceof Destination) {
            $place->setDestination($destination);
        }

        $category = $this->findRequestedCategory($request);
        $place->setCategory($category);

        $requestedStatus = ContentStatus::tryFrom($request->request->getString('status')) ?? $place->getStatus();
        $action = $request->request->getString('action', 'save');
        if ($action === 'publish') {
            $requestedStatus = ContentStatus::Published;
            $place->setPublishedAt($place->getPublishedAt() ?? new DateTimeImmutable());
        }
        if ($action === 'draft') {
            $requestedStatus = ContentStatus::Draft;
        }

        $place
            ->setStatus($requestedStatus)
            ->setShortDescription($this->nullIfBlank($request->request->getString('shortDescription')))
            ->setDescription($this->nullIfBlank($request->request->getString('description')))
            ->setAddress($this->nullIfBlank($request->request->getString('address')))
            ->setLatitude($this->nullableFloat($request->request->get('latitude')))
            ->setLongitude($this->nullableFloat($request->request->get('longitude')))
            ->setVisitDurationMinutes($this->nullableInt($request->request->get('visitDurationMinutes')))
            ->setDifficulty(PlaceDifficulty::tryFrom($request->request->getString('difficulty')) ?? PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::tryFrom($request->request->getString('priceType')) ?? PriceType::Unknown)
            ->setSeoTitle($this->nullIfBlank($request->request->getString('seoTitle')))
            ->setSeoDescription($this->nullIfBlank($request->request->getString('seoDescription')));
    }

    /** @return array<string, string> */
    private function destinationTypeOptions(): array
    {
        return [
            DestinationType::Country->value => 'Pays',
            DestinationType::Region->value => 'Région',
            DestinationType::Department->value => 'Département / province',
            DestinationType::City->value => 'Ville',
            DestinationType::Area->value => 'Zone / repérage',
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function destinationQuickCreateData(Place $place): array
    {
        return [
            'contextType' => 'place',
            'contextId' => $place->getId(),
            'targetType' => 'place',
            'targetId' => $place->getId(),
            'name' => '',
            'countryName' => '',
            'regionName' => '',
            'departmentName' => '',
            'cityName' => '',
            'parent' => null,
            'type' => DestinationType::Area->value,
            'code' => '',
            'latitude' => $place->getLatitude(),
            'longitude' => $place->getLongitude(),
        ];
    }

    /**
     * @param mixed $files
     *
     * @return list<UploadedFile>
     */
    private function normalizeUploadedFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @return array{title: string, path: string, thumbnailPath?: string|null, mimeType: string|null, fileSize: int|null, width: int|null, height: int|null, projection?: string|null, metadata?: array<string, mixed>|null}
     */
    private function storeImageByType(UploadedFile $file, ImageType $imageType, object|string|null $context = null): array
    {
        if ($imageType === ImageType::Degree360) {
            $storedFile = $this->panoramaUploadService->upload(
                $file,
                $this->mediaSeoTextService->filenameBaseForContext($context, MediaType::Image, $imageType),
            );

            return array_replace($storedFile, [
                'title' => $this->mediaSeoTextService->titleForContext($context, MediaType::Image, $imageType),
                'altText' => $this->mediaSeoTextService->altTextForContext($context, MediaType::Image, $imageType),
            ]);
        }

        return $this->storeUploadedImage($file, $context, $imageType);
    }

    /**
     * @return array{title: string, path: string, thumbnailPath?: string|null, mimeType: string|null, fileSize: int|null, width: int|null, height: int|null, projection?: string|null, metadata?: array<string, mixed>|null}
     */
    private function storeUploadedImage(UploadedFile $file, object|string|null $context = null, ?ImageType $imageType = null): array
    {
        $imageType ??= ImageType::Standard;
        $inspection = $this->imageUploadSecurity->inspect($file);
        $extension = $inspection['extension'];
        $safeName = $this->mediaSeoTextService->filenameBaseForContext($context, MediaType::Image, $imageType);
        $filename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $extension);
        $targetDirectory = $this->parameterBag->get('kernel.project_dir').'/public/'.self::UPLOAD_DIRECTORY;
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $file->move($targetDirectory, $filename);
        $sanitized = $this->imageMetadataSanitizer->sanitizePublicPath('/'.self::UPLOAD_DIRECTORY.'/'.$filename);

        return [
            'title' => $this->mediaSeoTextService->titleForContext($context, MediaType::Image, $imageType),
            'altText' => $this->mediaSeoTextService->altTextForContext($context, MediaType::Image, $imageType),
            'path' => '/'.self::UPLOAD_DIRECTORY.'/'.$filename,
            'mimeType' => $sanitized['mimeType'],
            'fileSize' => (int) (filesize($targetDirectory.'/'.$filename) ?: $inspection['fileSize']),
            'width' => $sanitized['width'],
            'height' => $sanitized['height'],
        ];
    }

    private function consumeUploadRateLimit(Request $request): bool
    {
        $limit = $this->actionRateLimiter->consumeAdminUpload(
            $request,
            $this->getUser() instanceof User ? $this->getUser() : null,
        );

        if ($limit->isAccepted()) {
            return true;
        }

        $this->addUploadRateLimitFlash($limit);

        return false;
    }

    private function addUploadRateLimitFlash(RateLimit $limit): void
    {
        $this->addFlash('warning', sprintf(
            'Trop d’envois de médias. Réessayez à partir de %s.',
            $limit->getRetryAfter()->format('H:i'),
        ));
    }

    /** @return list<PlaceMedia> */
    private function sortedMediaLinks(Place $place): array
    {
        $mediaLinks = $place->getMediaLinks()->toArray();
        usort($mediaLinks, static fn (PlaceMedia $a, PlaceMedia $b): int => [$a->getPosition(), $a->getId() ?? 0] <=> [$b->getPosition(), $b->getId() ?? 0]);

        return $mediaLinks;
    }

    private function isImmersiveLink(PlaceMedia $placeMedia): bool
    {
        $media = $placeMedia->getMediaAsset();
        if (!$media instanceof MediaAsset || $media->getMediaType() !== MediaType::Image) {
            return false;
        }

        return in_array($media->getImageType(), [ImageType::Degree360, ImageType::Degree180], true);
    }

    private function nextMediaPosition(Place $place): int
    {
        $maxPosition = -1;
        foreach ($place->getMediaLinks() as $mediaLink) {
            $maxPosition = max($maxPosition, $mediaLink->getPosition());
        }

        return $maxPosition + 1;
    }

    /**
     * @param array<int, \BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function enumChoices(array $cases, array $labels): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $labels[$case->value] ?? $case->value;
        }

        return $choices;
    }

    private function findRequestedDestination(Request $request): ?Destination
    {
        $destinationId = $this->nullableInt($request->request->get('destination'));

        return $destinationId !== null ? $this->destinationRepository->find($destinationId) : null;
    }

    private function findRequestedCategory(Request $request): ?Category
    {
        $categoryId = $this->nullableInt($request->request->get('category'));

        return $categoryId !== null ? $this->categoryRepository->find($categoryId) : null;
    }

    /** @return array<int, mixed> */
    private function requestArray(Request $request, string $key): array
    {
        $data = $request->request->all();
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, string> */
    private function photoUsageOptions(): array
    {
        return [
            self::MEDIA_USAGE_MAIN => 'Image principale',
            self::MEDIA_USAGE_GALLERY => 'Galerie générale',
        ];
    }

    /** @return array<string, string> */
    private function videoUsageOptions(): array
    {
        return [
            self::MEDIA_USAGE_GALLERY => 'Galerie générale',
        ];
    }

    private function resolvePhotoUsage(mixed $value): string
    {
        return (string) $value === self::MEDIA_USAGE_MAIN
            ? self::MEDIA_USAGE_MAIN
            : self::MEDIA_USAGE_GALLERY;
    }

    private function resolveVideoUsage(mixed $value): string
    {
        return self::MEDIA_USAGE_GALLERY;
    }

    private function mediaRoleForUsage(string $usage): MediaRole
    {
        return $usage === self::MEDIA_USAGE_MAIN ? MediaRole::Cover : MediaRole::Gallery;
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

    private function redirectToStudio(Place $place): RedirectResponse
    {
        return $this->redirectToRoute('admin_studio_place_edit', ['id' => $place->getId()]);
    }
}
