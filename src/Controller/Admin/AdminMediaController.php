<?php

namespace App\Controller\Admin;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\MediaAssetRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Service\Media\MediaVariantService;
use App\Service\Media\PublicMediaPathValidator;
use App\Service\Media\VideoThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminMediaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaVariantService $mediaVariantService,
        private readonly VideoThumbnailGenerator $videoThumbnailGenerator,
        private readonly PublicMediaPathValidator $publicMediaPathValidator,
    ) {
    }

    #[Route('/admin/media', name: 'admin_media_index', methods: ['GET'])]
    public function index(MediaAssetRepository $mediaAssetRepository): Response
    {
        return $this->render('admin/media/index.html.twig', [
            'media_assets' => $mediaAssetRepository->findBy([], ['createdAt' => 'DESC'], 100),
            'media_type_labels' => $this->mediaTypeLabels(),
            'image_type_labels' => $this->imageTypeLabels(),
            'video_type_labels' => $this->videoTypeLabels(),
        ]);
    }

    #[Route('/admin/media/new', name: 'admin_media_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $media = new MediaAsset();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_media_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $this->updateMediaFromRequest($media, $request);
            $user = $this->getUser();
            if ($user instanceof User) {
                $media->setUploadedBy($user);
            }
            $this->generateVideoThumbnailIfMissing($media);
            $this->mediaVariantService->generateForMedia($media, true);

            $this->entityManager->persist($media);
            $this->entityManager->flush();
            $this->addFlash('success', 'Média créé.');

            return $this->redirectToRoute('admin_media_index');
        }

        return $this->renderMediaForm($media, 'Nouveau média', 'Créer le média');
    }

    #[Route('/admin/media/{id}/edit', name: 'admin_media_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(MediaAsset $media, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_media_'.$media->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $previousFilePath = $media->getFilePath();
            $previousThumbnailPath = $media->getThumbnailPath();
            $this->updateMediaFromRequest($media, $request);
            $this->generateVideoThumbnailIfMissing($media);
            $forceVariants = $previousFilePath !== $media->getFilePath()
                || $previousThumbnailPath !== $media->getThumbnailPath();
            $this->mediaVariantService->generateForMedia($media, $forceVariants);
            $this->entityManager->flush();
            $this->addFlash('success', 'Média enregistré.');

            return $this->redirectToRoute('admin_media_index');
        }

        return $this->renderMediaForm($media, 'Modifier le média', 'Enregistrer');
    }

    #[Route('/admin/media/{id}/delete', name: 'admin_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(MediaAsset $media, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_media_delete_'.$media->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$this->canDelete($media)) {
            $this->addFlash('warning', 'Ce média est encore utilisé dans un contenu ou un Studio.');

            return $this->redirectToRoute('admin_media_index');
        }

        $this->entityManager->remove($media);
        $this->entityManager->flush();
        $this->addFlash('success', 'Média supprimé.');

        return $this->redirectToRoute('admin_media_index');
    }

    private function renderMediaForm(MediaAsset $media, string $title, string $submitLabel): Response
    {
        return $this->render('admin/media/form.html.twig', [
            'media' => $media,
            'media_type_options' => $this->mediaTypeLabels(),
            'image_type_options' => $this->imageTypeLabels(),
            'video_type_options' => $this->videoTypeLabels(),
            'title' => $title,
            'submit_label' => $submitLabel,
        ]);
    }

    private function updateMediaFromRequest(MediaAsset $media, Request $request): void
    {
        $mediaType = MediaType::tryFrom($request->request->getString('mediaType')) ?? MediaType::Image;
        try {
            $filePath = $this->publicMediaPathValidator->validateNullableUploadPath(
                $this->nullIfBlank($request->request->getString('filePath')),
                'filePath',
            );
            $thumbnailPath = $this->publicMediaPathValidator->validateNullableUploadPath(
                $this->nullIfBlank($request->request->getString('thumbnailPath')),
                'thumbnailPath',
            );
            $externalUrl = $this->publicMediaPathValidator->validateNullableHttpUrl(
                $this->nullIfBlank($request->request->getString('externalUrl')),
                'externalUrl',
            );
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        $media
            ->setTitle($this->nullIfBlank($request->request->getString('title')))
            ->setAltText($this->nullIfBlank($request->request->getString('altText')))
            ->setCaption($this->nullIfBlank($request->request->getString('caption')))
            ->setMediaType($mediaType)
            ->setImageType($mediaType === MediaType::Image ? (ImageType::tryFrom($request->request->getString('imageType')) ?? ImageType::Standard) : null)
            ->setVideoType($mediaType === MediaType::Video ? (VideoType::tryFrom($request->request->getString('videoType')) ?? VideoType::External) : null)
            ->setFilePath($filePath)
            ->setThumbnailPath($thumbnailPath)
            ->setExternalUrl($externalUrl);
    }

    private function generateVideoThumbnailIfMissing(MediaAsset $media): void
    {
        if ($media->getMediaType() === MediaType::Video && ($media->getThumbnailPath() === null || $media->getThumbnailPath() === '')) {
            $this->videoThumbnailGenerator->generateForMedia($media);
        }
    }

    private function canDelete(MediaAsset $media): bool
    {
        return $media->getFeaturedArticles()->isEmpty()
            && $media->getFeaturedPlaces()->isEmpty()
            && $media->getArticleLinks()->isEmpty()
            && $media->getPlaceLinks()->isEmpty()
            && $media->getHikeDraftLinks()->isEmpty()
            && $media->getCityVisitDraftLinks()->isEmpty()
            && $media->getHikePointLinks()->isEmpty()
            && $media->getCityVisitPointLinks()->isEmpty();
    }

    /** @return array<string, string> */
    private function mediaTypeLabels(): array
    {
        return [
            MediaType::Image->value => 'Image',
            MediaType::Video->value => 'Vidéo',
        ];
    }

    /** @return array<string, string> */
    private function imageTypeLabels(): array
    {
        return [
            ImageType::Standard->value => 'Image classique',
            ImageType::Degree360->value => 'Image 360°',
            ImageType::Degree180->value => 'Image 180°',
            ImageType::Panorama->value => 'Image panoramique',
            ImageType::WideAngle->value => 'Grand angle',
        ];
    }

    /** @return array<string, string> */
    private function videoTypeLabels(): array
    {
        return [
            VideoType::Local->value => 'Vidéo locale',
            VideoType::Youtube->value => 'YouTube',
            VideoType::Vimeo->value => 'Vimeo',
            VideoType::Dailymotion->value => 'Dailymotion',
            VideoType::External->value => 'Externe',
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
