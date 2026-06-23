<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleHike;
use App\Entity\ArticleMedia;
use App\Entity\Category;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\HikeDraftRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\Article\ArticleContentSanitizer;
use App\Service\ImageUploadSecurity;
use App\Service\Media\ImageMetadataSanitizer;
use App\Service\Media\MediaDeletionService;
use App\Service\Media\MediaSeoTextService;
use App\Service\Media\MediaVariantService;
use App\Service\Media\PublicMediaMasterCleanupService;
use App\Service\PublicationNotificationMailer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminArticleController extends AbstractController
{
    private const UPLOAD_DIRECTORY = 'uploads/media';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly ArticleContentSanitizer $articleContentSanitizer,
        private readonly ImageUploadSecurity $imageUploadSecurity,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
        private readonly MediaDeletionService $mediaDeletionService,
        private readonly MediaVariantService $mediaVariantService,
        private readonly PublicMediaMasterCleanupService $publicMediaMasterCleanupService,
        private readonly MediaSeoTextService $mediaSeoTextService,
        private readonly ParameterBagInterface $parameterBag,
        private readonly PublicationNotificationMailer $publicationNotificationMailer,
    ) {
    }

    #[Route('/admin/articles', name: 'admin_articles_index', methods: ['GET'])]
    public function index(ArticleRepository $articleRepository): Response
    {
        return $this->render('admin/articles/index.html.twig', [
            'articles' => $articleRepository->findBy([], ['updatedAt' => 'DESC', 'createdAt' => 'DESC'], 100),
            'status_labels' => $this->statusLabels(),
        ]);
    }

    #[Route('/admin/articles/new', name: 'admin_articles_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CategoryRepository $categoryRepository,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): Response {
        $article = new Article();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateArticleFromRequest($article, $request)) {
                $shouldNotifyPublication = $article->getPublishedAt() !== null && $article->getStatus() === ContentStatus::Published;
                $article->setSlug($this->createUniqueSlug($article->getTitle() ?? 'article', Article::class));
                $this->syncArticleRelations($article, $request);
                $orphanCandidates = $this->handleArticleMediaFromRequest($article, $request);
                $user = $this->getUser();
                if ($user instanceof User) {
                    $article->setAuthor($user);
                }

                $this->entityManager->persist($article);
                $this->entityManager->flush();
                $this->cleanupDetachedMedia($orphanCandidates);
                $this->notifyNewPublication($article, $shouldNotifyPublication);
                $this->addFlash('success', 'Article créé.');

                return $this->redirectToRoute('admin_articles_index');
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => $article,
            'categories' => $categoryRepository->findArticleCategories(),
            ...$this->articleRelationFormData($article, $hikeDraftRepository, $cityVisitDraftRepository),
            'status_options' => $this->statusLabels(),
            'role_options' => $this->roleLabels(),
            'title' => 'Nouvel article',
            'submit_label' => 'Créer l’article',
        ]);
    }

    #[Route('/admin/articles/{id}/edit', name: 'admin_articles_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Article $article,
        Request $request,
        CategoryRepository $categoryRepository,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): Response {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $article);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_'.$article->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $wasPublished = $article->getStatus() === ContentStatus::Published && $article->getPublishedAt() !== null;
            if ($this->updateArticleFromRequest($article, $request)) {
                $isPublished = $article->getStatus() === ContentStatus::Published;
                $shouldNotifyPublication = !$wasPublished && $isPublished;
                $this->syncArticleRelations($article, $request);
                $orphanCandidates = $this->handleArticleMediaFromRequest($article, $request);
                $this->entityManager->flush();
                $this->cleanupDetachedMedia($orphanCandidates);
                $this->notifyNewPublication($article, $shouldNotifyPublication);
                $this->addFlash('success', 'Article enregistré.');

                return $this->redirectToRoute('admin_articles_index');
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => $article,
            'categories' => $categoryRepository->findArticleCategories(),
            ...$this->articleRelationFormData($article, $hikeDraftRepository, $cityVisitDraftRepository),
            'status_options' => $this->statusLabels(),
            'role_options' => $this->roleLabels(),
            'title' => 'Modifier l’article',
            'submit_label' => 'Enregistrer',
        ]);
    }

    #[Route('/admin/articles/{id}/delete', name: 'admin_articles_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Article $article, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_article_delete_'.$article->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $article->setStatus(ContentStatus::Archived);
        $this->entityManager->flush();
        $this->addFlash('success', 'Article archivé.');

        return $this->redirectToRoute('admin_articles_index');
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            ContentStatus::Draft->value => 'Brouillon',
            ContentStatus::Published->value => 'Publié',
            ContentStatus::PrivateContent->value => 'Privé',
            ContentStatus::Archived->value => 'Archivé',
        ];
    }

    private function updateArticleFromRequest(Article $article, Request $request): bool
    {
        $previousTitle = $article->getTitle();
        $previousStatus = $article->getStatus();
        $title = trim($request->request->getString('title'));
        $rawContent = trim($request->request->getString('content'));
        $content = $this->articleContentSanitizer->sanitize($rawContent);
        if ($title === '' || $rawContent === '' || $this->plainContent($content) === '') {
            $this->addFlash('error', 'Le titre et le contenu sont obligatoires.');

            return false;
        }

        $categoryId = $this->nullableInt($request->request->get('category'));
        $status = ContentStatus::tryFrom($request->request->getString('status')) ?? ContentStatus::Draft;

        $article
            ->setTitle($title)
            ->setCategory($categoryId !== null ? $this->entityManager->find(Category::class, $categoryId) : null)
            ->setExcerpt($this->nullIfBlank($request->request->getString('excerpt')))
            ->setContent($content)
            ->setStatus($status);

        if ($status === ContentStatus::Published && $article->getPublishedAt() === null) {
            $article->setPublishedAt(new DateTimeImmutable());
        }

        if (
            $article->getId() !== null
            && $previousStatus !== ContentStatus::Published
            && $status !== ContentStatus::Published
            && $title !== $previousTitle
        ) {
            $article->setSlug($this->createUniqueSlug($title, Article::class, $article));
        }

        return true;
    }

    private function notifyNewPublication(Article $article, bool $shouldNotify): void
    {
        if (!$shouldNotify) {
            return;
        }

        $report = $this->publicationNotificationMailer->sendNewPublicationNotification($article);
        if ($report['errorCount'] > 0) {
            $this->addFlash('warning', 'La publication a été enregistrée, mais l’envoi des notifications a rencontré une erreur.');
        }
    }

    /** @param class-string<Article> $entityClass */
    private function createUniqueSlug(string $name, string $entityClass, ?Article $currentArticle = null): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($name));
        $baseSlug = trim($baseSlug, '-') ?: 'contenu';
        $slug = $baseSlug;
        $suffix = 2;
        $repository = $this->entityManager->getRepository($entityClass);

        while (($existing = $repository->findOneBy(['slug' => $slug])) !== null && (!$currentArticle instanceof Article || $existing->getId() !== $currentArticle->getId())) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }

    /**
     * @return array{
     *     hike_options: list<HikeDraft>,
     *     city_visit_options: list<CityVisitDraft>,
     *     selected_link_type: string,
     *     selected_hike_id: ?int,
     *     selected_city_visit_id: ?int,
     *     selected_article_role: string
     * }
     */
    private function articleRelationFormData(
        Article $article,
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): array {
        $hikeLinks = $article->getHikeLinks()->toArray();
        $cityVisitLinks = $article->getCityVisitLinks()->toArray();
        $firstHikeLink = $hikeLinks[0] ?? null;
        $firstCityVisitLink = $cityVisitLinks[0] ?? null;
        $selectedHike = $firstHikeLink?->getHikeDraft();
        $selectedCityVisit = $firstCityVisitLink?->getCityVisitDraft();
        $selectedLinkType = $selectedHike instanceof HikeDraft ? 'hike' : ($selectedCityVisit instanceof CityVisitDraft ? 'city_visit' : 'none');

        return [
            'hike_options' => $this->sortTourismContentOptions($hikeDraftRepository->findBy([], ['updatedAt' => 'DESC', 'id' => 'DESC'], 200)),
            'city_visit_options' => $this->sortTourismContentOptions($cityVisitDraftRepository->findBy([], ['updatedAt' => 'DESC', 'id' => 'DESC'], 200)),
            'selected_link_type' => $selectedLinkType,
            'selected_hike_id' => $selectedHike?->getId(),
            'selected_city_visit_id' => $selectedCityVisit?->getId(),
            'selected_article_role' => $firstHikeLink?->getRole() ?? $firstCityVisitLink?->getRole() ?? 'related',
        ];
    }

    private function syncArticleRelations(Article $article, Request $request): void
    {
        $linkType = $request->request->getString('linkedContentType', 'none');
        $role = $this->normalizeRole($request->request->getString('articleRole', 'related'));

        if ($linkType === 'hike') {
            $this->syncHikeLinks($article, $this->nullableInt($request->request->get('linkedHike')), $role);
            $this->syncCityVisitLinks($article, null, $role);

            return;
        }

        if ($linkType === 'city_visit') {
            $this->syncHikeLinks($article, null, $role);
            $this->syncCityVisitLinks($article, $this->nullableInt($request->request->get('linkedCityVisit')), $role);

            return;
        }

        $this->syncHikeLinks($article, null, $role);
        $this->syncCityVisitLinks($article, null, $role);
    }

    private function syncHikeLinks(Article $article, ?int $id, string $role): void
    {
        $existingLink = null;
        foreach ($article->getHikeLinks()->toArray() as $link) {
            if ($id !== null && $link->getHikeDraft()?->getId() === $id) {
                $existingLink = $link;
                continue;
            }

            $article->getHikeLinks()->removeElement($link);
        }

        if ($id !== null) {
            $hike = $this->entityManager->find(HikeDraft::class, $id);
            if ($hike instanceof HikeDraft) {
                $link = $existingLink instanceof ArticleHike ? $existingLink : (new ArticleHike())->setArticle($article)->setHikeDraft($hike);
                $link->setRole($role)->setPosition(0);

                if (!$article->getHikeLinks()->contains($link)) {
                    $article->getHikeLinks()->add($link);
                }
            }
        }
    }

    private function syncCityVisitLinks(Article $article, ?int $id, string $role): void
    {
        $existingLink = null;
        foreach ($article->getCityVisitLinks()->toArray() as $link) {
            if ($id !== null && $link->getCityVisitDraft()?->getId() === $id) {
                $existingLink = $link;
                continue;
            }

            $article->getCityVisitLinks()->removeElement($link);
        }

        if ($id !== null) {
            $cityVisit = $this->entityManager->find(CityVisitDraft::class, $id);
            if ($cityVisit instanceof CityVisitDraft) {
                $link = $existingLink instanceof ArticleCityVisit ? $existingLink : (new ArticleCityVisit())->setArticle($article)->setCityVisitDraft($cityVisit);
                $link->setRole($role)->setPosition(0);

                if (!$article->getCityVisitLinks()->contains($link)) {
                    $article->getCityVisitLinks()->add($link);
                }
            }
        }
    }

    /**
     * @template T of HikeDraft|CityVisitDraft
     *
     * @param array<int, T> $contents
     *
     * @return list<T>
     */
    private function sortTourismContentOptions(array $contents): array
    {
        usort($contents, function (HikeDraft|CityVisitDraft $first, HikeDraft|CityVisitDraft $second): int {
            $firstAutomatic = $this->isAutomaticTitle($first->getTitle());
            $secondAutomatic = $this->isAutomaticTitle($second->getTitle());

            if ($firstAutomatic !== $secondAutomatic) {
                return $firstAutomatic <=> $secondAutomatic;
            }

            return strcasecmp((string) $first->getTitle(), (string) $second->getTitle());
        });

        return $contents;
    }

    private function isAutomaticTitle(?string $title): bool
    {
        return preg_match('/^(Randonnée|Visite de ville) du \d{2}\/\d{2}\/\d{4}/', (string) $title) === 1;
    }

    private function normalizeRole(string $role): string
    {
        return array_key_exists($role, $this->roleLabels()) ? $role : 'related';
    }

    /** @return list<MediaAsset> */
    private function handleArticleMediaFromRequest(Article $article, Request $request): array
    {
        $orphanCandidates = [];

        if ($request->request->getBoolean('removeCover')) {
            $this->demoteArticleCover($article);
        }

        foreach ($this->requestIds($request, 'removeMediaLinks') as $mediaLinkId) {
            $candidate = $this->removeArticleMediaLink($article, $mediaLinkId);
            if ($candidate instanceof MediaAsset) {
                $orphanCandidates[] = $candidate;
            }
        }

        $promotedMediaLinkId = $this->nullableInt($request->request->get('promoteCoverMedia'));
        if ($promotedMediaLinkId !== null) {
            $this->promoteArticleMediaToCover($article, $promotedMediaLinkId);
        }

        $coverFile = $request->files->get('coverImage');
        if ($coverFile instanceof UploadedFile && $coverFile->getSize() !== false && $coverFile->getSize() > 0) {
            $media = $this->createArticleImageAssetFromUpload($coverFile, $article, MediaRole::Cover);
            if ($media instanceof MediaAsset) {
                $this->demoteArticleCover($article);
                $this->attachArticleMedia($article, $media, MediaRole::Cover, 0);
                $article->setFeaturedImage($media);
            }
        }

        foreach ($this->normalizeUploadedFiles($request->files->get('galleryImages')) as $file) {
            if ($file->getSize() === false || $file->getSize() <= 0) {
                continue;
            }

            $media = $this->createArticleImageAssetFromUpload($file, $article, MediaRole::Gallery);
            if ($media instanceof MediaAsset) {
                $this->attachArticleMedia($article, $media, MediaRole::Gallery, $this->nextMediaPosition($article));
            }
        }

        $this->normalizeArticleCoverRole($article);

        return $this->uniqueMediaAssets($orphanCandidates);
    }

    private function demoteArticleCover(Article $article): void
    {
        $featuredImage = $article->getFeaturedImage();
        $featuredImageHasLink = false;

        foreach ($article->getMediaLinks()->toArray() as $link) {
            if ($featuredImage instanceof MediaAsset && $link->getMediaAsset() === $featuredImage) {
                $featuredImageHasLink = true;
            }

            if ($link->getRole() === MediaRole::Cover) {
                $link->setRole(MediaRole::Gallery);
            }
        }

        if ($featuredImage instanceof MediaAsset && !$featuredImageHasLink) {
            $this->attachArticleMedia($article, $featuredImage, MediaRole::Gallery, $this->nextMediaPosition($article));
        }

        $article->setFeaturedImage(null);
    }

    private function promoteArticleMediaToCover(Article $article, int $mediaLinkId): void
    {
        $selectedLink = null;
        foreach ($article->getMediaLinks()->toArray() as $link) {
            if ($link->getId() === $mediaLinkId) {
                $selectedLink = $link;
                break;
            }
        }

        if (!$selectedLink instanceof ArticleMedia) {
            return;
        }

        $media = $selectedLink->getMediaAsset();
        if (!$media instanceof MediaAsset || $media->getMediaType() !== MediaType::Image) {
            return;
        }

        foreach ($article->getMediaLinks()->toArray() as $link) {
            if ($link !== $selectedLink && $link->getRole() === MediaRole::Cover) {
                $link->setRole(MediaRole::Gallery);
            }
        }

        $selectedLink->setRole(MediaRole::Cover);
        $article->setFeaturedImage($media);
    }

    private function normalizeArticleCoverRole(Article $article): void
    {
        $featuredImage = $article->getFeaturedImage();
        $featuredLink = null;
        $keptCoverLink = null;

        foreach ($article->getMediaLinks()->toArray() as $link) {
            $media = $link->getMediaAsset();
            if (!$media instanceof MediaAsset || $media->getMediaType() !== MediaType::Image) {
                continue;
            }

            if ($featuredImage instanceof MediaAsset && $media === $featuredImage) {
                $featuredLink = $link;
            }

            if ($link->getRole() !== MediaRole::Cover) {
                continue;
            }

            if (!$keptCoverLink instanceof ArticleMedia) {
                $keptCoverLink = $link;
                continue;
            }

            $link->setRole(MediaRole::Gallery);
        }

        if ($featuredLink instanceof ArticleMedia) {
            if ($keptCoverLink instanceof ArticleMedia && $keptCoverLink !== $featuredLink) {
                $keptCoverLink->setRole(MediaRole::Gallery);
            }

            $featuredLink->setRole(MediaRole::Cover);
            return;
        }

        if ($keptCoverLink instanceof ArticleMedia) {
            $article->setFeaturedImage($keptCoverLink->getMediaAsset());
        }
    }

    private function removeArticleMediaLink(Article $article, int $mediaLinkId): ?MediaAsset
    {
        foreach ($article->getMediaLinks()->toArray() as $link) {
            if ($link->getId() !== $mediaLinkId) {
                continue;
            }

            $removedMedia = $link->getMediaAsset();
            if ($link->getRole() === MediaRole::Cover) {
                $article->setFeaturedImage(null);
            }

            $article->getMediaLinks()->removeElement($link);
            $this->entityManager->remove($link);

            return $removedMedia;
        }

        return null;
    }

    private function attachArticleMedia(Article $article, MediaAsset $media, MediaRole $role, int $position): void
    {
        $link = (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);

        $article->getMediaLinks()->add($link);
        $this->entityManager->persist($media);
        $this->entityManager->persist($link);
    }

    private function nextMediaPosition(Article $article): int
    {
        $position = 0;
        foreach ($article->getMediaLinks() as $link) {
            $position = max($position, $link->getPosition() + 1);
        }

        return $position;
    }

    private function createArticleImageAssetFromUpload(UploadedFile $file, Article $article, MediaRole $role): ?MediaAsset
    {
        try {
            $storedFile = $this->storeArticleImage($file, $article);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('warning', sprintf('Image "%s" ignorée : %s', $file->getClientOriginalName(), $exception->getMessage()));

            return null;
        }

        $caption = $role === MediaRole::Cover ? 'Image de couverture' : null;
        $media = (new MediaAsset())
            ->setUploadedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setTitle($this->truncate($storedFile['title'], 180))
            ->setAltText($storedFile['altText'])
            ->setCaption($caption)
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath($storedFile['path'])
            ->setMimeType($storedFile['mimeType'])
            ->setFileSize($storedFile['fileSize'])
            ->setWidth($storedFile['width'])
            ->setHeight($storedFile['height']);

        $variantResult = $this->mediaVariantService->generateForMedia($media);
        if ($variantResult['status'] === 'error') {
            $this->addFlash('warning', 'L’image a été ajoutée, mais ses variantes responsive n’ont pas pu être générées.');
        } else {
            $this->publicMediaMasterCleanupService->cleanupIfSafe($media);
        }

        return $media;
    }

    /**
     * @return array{title: string, altText: string, path: string, mimeType: string, fileSize: int, width: int, height: int}
     */
    private function storeArticleImage(UploadedFile $file, Article $article): array
    {
        $inspection = $this->imageUploadSecurity->inspect($file);
        if ($inspection['mimeType'] === 'image/gif') {
            throw new InvalidArgumentException('les GIF ne sont pas pris en charge dans les articles pour garantir le nettoyage des métadonnées.');
        }

        $filename = sprintf(
            '%s-%s.%s',
            $this->mediaSeoTextService->filenameBaseForContext($article, MediaType::Image, ImageType::Standard),
            bin2hex(random_bytes(6)),
            $inspection['extension'],
        );
        $targetDirectory = $this->parameterBag->get('kernel.project_dir').'/public/'.self::UPLOAD_DIRECTORY;
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $file->move($targetDirectory, $filename);
        $publicPath = '/'.self::UPLOAD_DIRECTORY.'/'.$filename;
        $sanitized = $this->imageMetadataSanitizer->sanitizePublicPath($publicPath);

        return [
            'title' => $this->mediaSeoTextService->titleForContext($article, MediaType::Image, ImageType::Standard),
            'altText' => $this->mediaSeoTextService->altTextForContext($article, MediaType::Image, ImageType::Standard),
            'path' => $publicPath,
            'mimeType' => $sanitized['mimeType'],
            'fileSize' => (int) (filesize($targetDirectory.'/'.$filename) ?: $inspection['fileSize']),
            'width' => $sanitized['width'],
            'height' => $sanitized['height'],
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

    /** @return list<int> */
    private function requestIds(Request $request, string $key): array
    {
        $data = $request->request->all();
        $values = $data[$key] ?? [];
        if (!is_array($values)) {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            $id = $this->positiveIntOrNull($value);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function plainContent(string $html): string
    {
        $withoutMediaTokens = preg_replace('/\[\[media:\d+\]\]/', '', $html) ?? $html;

        return trim(html_entity_decode(strip_tags($withoutMediaTokens), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param list<MediaAsset> $orphanCandidates */
    private function cleanupDetachedMedia(array $orphanCandidates): void
    {
        if ($orphanCandidates === []) {
            return;
        }

        $this->entityManager->flush();
        foreach ($orphanCandidates as $media) {
            $result = $this->mediaDeletionService->deleteIfOrphan($media);
            if ($result['deleted']) {
                $this->addFlash('success', sprintf('Ancien média #%d supprimé car il n’était plus utilisé.', $result['mediaId']));
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<MediaAsset> $mediaAssets
     *
     * @return list<MediaAsset>
     */
    private function uniqueMediaAssets(array $mediaAssets): array
    {
        $unique = [];
        foreach ($mediaAssets as $media) {
            $unique[spl_object_id($media)] = $media;
        }

        return array_values($unique);
    }

    /** @return array<string, string> */
    private function roleLabels(): array
    {
        return [
            'related' => 'Article lié',
            'history' => 'Histoire',
            'legend' => 'Légende',
            'practical' => 'Infos pratiques',
            'context' => 'Récit / découverte',
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $this->positiveIntOrNull($value);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $digits = ltrim($value, '0');
        if (!ctype_digit($value) || $digits === '' || strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)
        ) {
            return null;
        }

        return (int) $value;
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
