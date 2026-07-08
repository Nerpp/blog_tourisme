<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\ArticleCityVisit;
use App\Entity\ArticleHike;
use App\Entity\ArticleMedia;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\MediaAsset;
use App\Entity\PublicationNotificationLog;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\CityVisitDraftRepository;
use App\Repository\CommentRepository;
use App\Repository\HikeDraftRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use App\Service\Article\ArticleContentSanitizer;
use App\Service\CommentDeletionService;
use App\Service\CommentReactionViewService;
use App\Service\ImageUploadSecurity;
use App\Service\Media\ImageVariantGenerator;
use App\Service\Media\ImageMetadataSanitizer;
use App\Service\Media\MediaDeletionService;
use App\Service\Media\MediaSeoTextService;
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
    private const ARTICLE_INLINE_MAX_LONG_SIDE = 640;
    private const ARTICLE_DISPLAY_MAX_LONG_SIDE = 960;
    private const ARTICLE_COVER_MAX_LONG_SIDE = 1280;
    private const ARTICLE_SOURCE_MAX_LONG_SIDE = 1600;
    private const ARTICLE_SUBMISSIONS_SESSION_KEY = 'admin_article_form_submissions';
    private const ARTICLE_SUBMISSION_PENDING = 'pending';
    private const ARTICLE_SUBMISSION_COMPLETED = 'completed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly CategoryRepository $categoryRepository,
        private readonly ArticleContentSanitizer $articleContentSanitizer,
        private readonly CommentDeletionService $commentDeletionService,
        private readonly ImageUploadSecurity $imageUploadSecurity,
        private readonly ImageVariantGenerator $imageVariantGenerator,
        private readonly ImageMetadataSanitizer $imageMetadataSanitizer,
        private readonly MediaDeletionService $mediaDeletionService,
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
        HikeDraftRepository $hikeDraftRepository,
        CityVisitDraftRepository $cityVisitDraftRepository,
    ): Response {
        $article = new Article();
        $articleSubmissionToken = $this->articleSubmissionToken($request);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $completedArticleId = $this->completedArticleIdForSubmission($request, $articleSubmissionToken);
            if ($completedArticleId !== null) {
                $this->addFlash('info', 'Cet article a déjà été enregistré.');

                return $this->redirectToRoute('admin_articles_index');
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
                $this->completeArticleSubmission($request, $articleSubmissionToken, $article);
                $this->cleanupDetachedMedia($orphanCandidates);
                $this->notifyNewPublication($article, $shouldNotifyPublication);
                $this->addFlash('success', 'Article créé.');

                return $this->redirectToRoute('admin_articles_index');
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => $article,
            'categories' => $this->categoryRepository->findArticleCategories(),
            ...$this->articleRelationFormData($article, $hikeDraftRepository, $cityVisitDraftRepository, $request),
            'status_options' => $this->statusLabels(),
            'role_options' => $this->roleLabels(),
            'title' => 'Nouvel article',
            'submit_label' => 'Créer l’article',
            'article_submission_token' => $articleSubmissionToken,
        ]);
    }

    #[Route('/admin/articles/{id}/edit', name: 'admin_articles_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Article $article,
        Request $request,
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
            'categories' => $this->categoryRepository->findArticleCategories(),
            ...$this->articleRelationFormData($article, $hikeDraftRepository, $cityVisitDraftRepository, $request),
            'status_options' => $this->statusLabels(),
            'role_options' => $this->roleLabels(),
            'title' => 'Modifier l’article',
            'submit_label' => 'Enregistrer',
        ]);
    }

    #[Route('/admin/articles/{id}/preview', name: 'admin_articles_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(
        Article $article,
        CommentRepository $commentRepository,
        CommentReactionViewService $reactionViewService,
    ): Response {
        $viewer = $this->getUser();
        $comments = $commentRepository->findApprovedForArticle($article, $viewer instanceof User ? $viewer : null, 'recent');
        $reactionContext = $reactionViewService->buildContext($comments, $viewer instanceof User ? $viewer : null);

        $response = $this->render('article/show.html.twig', [
            'article' => $article,
            'comments' => $comments,
            'comment_form' => null,
            'comments_sort' => 'recent',
            'comments_count' => $reactionContext['comment_count'],
            'comment_like_counts' => $reactionContext['like_counts'],
            'liked_comment_ids' => $reactionContext['liked_comment_ids'],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/admin/articles/{id}/archive', name: 'admin_articles_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Article $article, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_article_archive_'.$article->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $article->setStatus(ContentStatus::Archived);
        $this->entityManager->flush();
        $this->addFlash('success', 'Article archivé.');

        return $this->redirectToRoute('admin_articles_index');
    }

    #[Route('/admin/articles/{id}/delete', name: 'admin_articles_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Article $article, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_article_delete_'.$article->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $articleId = $article->getId();
        $articleTitle = (string) $article->getTitle();
        $orphanCandidates = $this->articleMediaCandidates($article);

        foreach ($article->getComments()->toArray() as $comment) {
            if ($comment->getParent() === null) {
                $this->commentDeletionService->deletePhysically($comment);
            }
        }

        $this->removeArticleOwnedRelations($article);
        $article->setFeaturedImage(null);

        if ($articleId !== null) {
            foreach ($this->entityManager->getRepository(PublicationNotificationLog::class)->findBy([
                'contentType' => 'article',
                'contentId' => $articleId,
            ]) as $notificationLog) {
                $this->entityManager->remove($notificationLog);
            }
        }

        $this->entityManager->remove($article);
        $this->entityManager->flush();
        $this->cleanupDetachedMedia($orphanCandidates, false);
        $this->addFlash('success', sprintf('L’article « %s » a été supprimé définitivement.', $articleTitle));

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
        $status = ContentStatus::tryFrom($request->request->getString('status')) ?? ContentStatus::Draft;

        if ($request->request->has('category')) {
            $categoryId = $this->nullableInt($request->request->get('category'));
            $article->setCategory($categoryId !== null ? $this->categoryRepository->findOneArticleCategoryById($categoryId) : null);
        }

        if ($title === '' || $rawContent === '' || $this->plainContent($content) === '') {
            $this->addFlash('error', 'Le titre et le contenu sont obligatoires.');

            return false;
        }

        $article
            ->setTitle($title)
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

    private function articleSubmissionToken(Request $request): string
    {
        $submittedToken = $request->request->getString('_submission_token');
        if ($request->isMethod('POST') && $this->isArticleSubmissionToken($submittedToken)) {
            return $submittedToken;
        }

        $token = bin2hex(random_bytes(16));
        $submissions = $this->articleSubmissions($request);
        $submissions[$token] = [
            'status' => self::ARTICLE_SUBMISSION_PENDING,
            'createdAt' => time(),
        ];
        $this->storeArticleSubmissions($request, $submissions);

        return $token;
    }

    private function completedArticleIdForSubmission(Request $request, string $token): ?int
    {
        if (!$this->isArticleSubmissionToken($token)) {
            return null;
        }

        $entry = $this->articleSubmissions($request)[$token] ?? null;
        if (!is_array($entry) || ($entry['status'] ?? null) !== self::ARTICLE_SUBMISSION_COMPLETED) {
            return null;
        }

        $articleId = $entry['articleId'] ?? null;

        return is_int($articleId) && $articleId > 0 ? $articleId : null;
    }

    private function completeArticleSubmission(Request $request, string $token, Article $article): void
    {
        if (!$this->isArticleSubmissionToken($token) || $article->getId() === null) {
            return;
        }

        $submissions = $this->articleSubmissions($request);
        $submissions[$token] = [
            'status' => self::ARTICLE_SUBMISSION_COMPLETED,
            'articleId' => $article->getId(),
            'createdAt' => time(),
        ];
        $this->storeArticleSubmissions($request, $submissions);
    }

    private function isArticleSubmissionToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /**
     * @return array<string, array{status: string|null, articleId: int|null, createdAt: int|null}>
     */
    private function articleSubmissions(Request $request): array
    {
        $rawSubmissions = $request->getSession()->get(self::ARTICLE_SUBMISSIONS_SESSION_KEY, []);
        if (!is_array($rawSubmissions)) {
            return [];
        }

        $submissions = [];
        foreach ($rawSubmissions as $token => $entry) {
            if (!is_string($token) || !$this->isArticleSubmissionToken($token) || !is_array($entry)) {
                continue;
            }

            $submissions[$token] = [
                'status' => is_string($entry['status'] ?? null) ? $entry['status'] : null,
                'articleId' => is_int($entry['articleId'] ?? null) ? $entry['articleId'] : null,
                'createdAt' => is_int($entry['createdAt'] ?? null) ? $entry['createdAt'] : null,
            ];
        }

        return $submissions;
    }

    /**
     * @param array<string, array{status?: string|null, articleId?: int|null, createdAt?: int|null}> $submissions
     */
    private function storeArticleSubmissions(Request $request, array $submissions): void
    {
        uasort($submissions, static fn (array $first, array $second): int => ($second['createdAt'] ?? 0) <=> ($first['createdAt'] ?? 0));

        $request->getSession()->set(
            self::ARTICLE_SUBMISSIONS_SESSION_KEY,
            array_slice($submissions, 0, 20, true),
        );
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
        Request $request,
    ): array {
        $hikeLinks = $article->getHikeLinks()->toArray();
        $cityVisitLinks = $article->getCityVisitLinks()->toArray();
        $firstHikeLink = $hikeLinks[0] ?? null;
        $firstCityVisitLink = $cityVisitLinks[0] ?? null;
        $selectedHike = $firstHikeLink?->getHikeDraft();
        $selectedCityVisit = $firstCityVisitLink?->getCityVisitDraft();
        $selectedLinkType = $selectedHike instanceof HikeDraft ? 'hike' : ($selectedCityVisit instanceof CityVisitDraft ? 'city_visit' : 'none');
        $selectedHikeId = $selectedHike?->getId();
        $selectedCityVisitId = $selectedCityVisit?->getId();
        $selectedArticleRole = $firstHikeLink?->getRole() ?? $firstCityVisitLink?->getRole() ?? 'related';

        if ($request->isMethod('POST')) {
            $submittedLinkType = $request->request->getString('linkedContentType', 'none');
            $selectedLinkType = in_array($submittedLinkType, ['hike', 'city_visit'], true) ? $submittedLinkType : 'none';
            $selectedHikeId = $selectedLinkType === 'hike' ? $this->nullableInt($request->request->get('linkedHike')) : null;
            $selectedCityVisitId = $selectedLinkType === 'city_visit' ? $this->nullableInt($request->request->get('linkedCityVisit')) : null;
            $selectedArticleRole = $this->normalizeRole($request->request->getString('articleRole', 'related'));
        }

        return [
            'hike_options' => $this->sortTourismContentOptions($hikeDraftRepository->findBy([], ['updatedAt' => 'DESC', 'id' => 'DESC'], 200)),
            'city_visit_options' => $this->sortTourismContentOptions($cityVisitDraftRepository->findBy([], ['updatedAt' => 'DESC', 'id' => 'DESC'], 200)),
            'selected_link_type' => $selectedLinkType,
            'selected_hike_id' => $selectedHikeId,
            'selected_city_visit_id' => $selectedCityVisitId,
            'selected_article_role' => $selectedArticleRole,
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
    private function articleMediaCandidates(Article $article): array
    {
        $candidates = [];
        $featuredImage = $article->getFeaturedImage();
        if ($featuredImage instanceof MediaAsset) {
            $candidates[$featuredImage->getId() ?? spl_object_id($featuredImage)] = $featuredImage;
        }

        foreach ($article->getMediaLinks() as $mediaLink) {
            $media = $mediaLink->getMediaAsset();
            if ($media instanceof MediaAsset) {
                $candidates[$media->getId() ?? spl_object_id($media)] = $media;
            }
        }

        preg_match_all('/\[\[media:(\d+)\]\]/', (string) $article->getContent(), $matches);
        $referencedIds = [];
        foreach ($matches[1] as $rawId) {
            $mediaId = $this->positiveIntOrNull($rawId);
            if ($mediaId !== null && !isset($candidates[$mediaId])) {
                $referencedIds[$mediaId] = $mediaId;
            }
        }

        if ($referencedIds !== []) {
            foreach ($this->entityManager->getRepository(MediaAsset::class)->findBy(['id' => array_values($referencedIds)]) as $media) {
                $candidates[$media->getId() ?? spl_object_id($media)] = $media;
            }
        }

        return array_values($candidates);
    }

    private function removeArticleOwnedRelations(Article $article): void
    {
        foreach ($article->getDestinationLinks() as $link) {
            $this->entityManager->remove($link);
        }
        foreach ($article->getPlaceLinks() as $link) {
            $this->entityManager->remove($link);
        }
        foreach ($article->getHikeLinks() as $link) {
            $this->entityManager->remove($link);
        }
        foreach ($article->getCityVisitLinks() as $link) {
            $this->entityManager->remove($link);
        }
        foreach ($article->getMediaLinks() as $link) {
            $this->entityManager->remove($link);
        }
        foreach ($article->getTagLinks() as $link) {
            $this->entityManager->remove($link);
        }
    }

    /** @return list<MediaAsset> */
    private function handleArticleMediaFromRequest(Article $article, Request $request): array
    {
        $orphanCandidates = [];
        $removedMediaIds = [];

        $legacyFeaturedMediaId = $this->nullableInt($request->request->get('removeFeaturedImage'));
        if ($legacyFeaturedMediaId !== null) {
            $legacyFeaturedMedia = $this->removeLegacyFeaturedImage($article, $legacyFeaturedMediaId);
            if ($legacyFeaturedMedia instanceof MediaAsset) {
                $orphanCandidates[] = $legacyFeaturedMedia;
                if ($legacyFeaturedMedia->getId() !== null) {
                    $removedMediaIds[] = $legacyFeaturedMedia->getId();
                }
            }
        }

        foreach ($this->requestIds($request, 'removeMediaLinks') as $mediaLinkId) {
            $candidate = $this->removeArticleMediaLink($article, $mediaLinkId);
            if ($candidate instanceof MediaAsset) {
                $orphanCandidates[] = $candidate;
                if ($candidate->getId() !== null) {
                    $removedMediaIds[] = $candidate->getId();
                }
            }
        }

        if ($removedMediaIds !== []) {
            $this->removeArticleMediaReferencesFromContent($article, $removedMediaIds);
        }

        $promotedMediaLinkId = $this->nullableInt($request->request->get('promoteCoverMedia'));
        if ($promotedMediaLinkId !== null) {
            $this->promoteArticleMediaToCover($article, $promotedMediaLinkId);
        }

        $galleryFiles = $this->normalizeUploadedFiles($request->files->get('galleryImages'));
        $newCoverImageIndex = $this->nonNegativeIntOrNull($request->request->get('newCoverImageIndex'));
        if ($newCoverImageIndex !== null && array_key_exists($newCoverImageIndex, $galleryFiles)) {
            $this->demoteArticleCover($article);
        }

        foreach ($galleryFiles as $index => $file) {
            if ($file->getSize() === false || $file->getSize() <= 0) {
                continue;
            }

            $role = $index === $newCoverImageIndex ? MediaRole::Cover : MediaRole::Gallery;
            $media = $this->createArticleImageAssetFromUpload($file, $article, $role);
            if ($media instanceof MediaAsset) {
                $this->attachArticleMedia($article, $media, $role, $this->nextMediaPosition($article));
                if ($role === MediaRole::Cover) {
                    $article->setFeaturedImage($media);
                }
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
            if (
                $link->getRole() === MediaRole::Cover
                || (
                    $removedMedia instanceof MediaAsset
                    && $article->getFeaturedImage() instanceof MediaAsset
                    && $article->getFeaturedImage()->getId() === $removedMedia->getId()
                )
            ) {
                $article->setFeaturedImage(null);
            }

            $article->getMediaLinks()->removeElement($link);
            $this->entityManager->remove($link);

            return $removedMedia;
        }

        return null;
    }

    private function removeLegacyFeaturedImage(Article $article, int $mediaId): ?MediaAsset
    {
        $featuredImage = $article->getFeaturedImage();
        if (!$featuredImage instanceof MediaAsset || $featuredImage->getId() !== $mediaId) {
            return null;
        }

        foreach ($article->getMediaLinks() as $link) {
            if ($link->getMediaAsset()?->getId() === $mediaId) {
                return null;
            }
        }

        $article->setFeaturedImage(null);

        return $featuredImage;
    }

    /** @param list<int> $mediaIds */
    private function removeArticleMediaReferencesFromContent(Article $article, array $mediaIds): void
    {
        $content = (string) $article->getContent();
        if ($content === '') {
            return;
        }

        foreach (array_unique($mediaIds) as $mediaId) {
            $content = str_replace(sprintf('[[media:%d]]', $mediaId), ' ', $content);
        }

        $content = $this->cleanArticleContentAfterMediaRemoval($content);
        $content = $this->articleContentSanitizer->sanitize($content);
        $content = $this->cleanArticleContentAfterMediaRemoval($content);

        $article->setContent($content);
    }

    private function cleanArticleContentAfterMediaRemoval(string $content): string
    {
        $content = preg_replace('/[ \t\x{00A0}]{2,}/u', ' ', $content) ?? $content;
        $content = preg_replace('/\s+([,.;:!?])/u', '$1', $content) ?? $content;
        $content = preg_replace('/(?:\r?\n[ \t]*){3,}/', "\n\n", $content) ?? $content;

        do {
            $previous = $content;
            $content = preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#iu', '', $content) ?? $content;
            $content = preg_replace('#<figure\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</figure>#iu', '', $content) ?? $content;
        } while ($content !== $previous);

        return trim($content);
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
            ->setFilePath($storedFile['variants']['source']['path'])
            ->setThumbnailPath($storedFile['variants']['thumb']['webp'])
            ->setMimeType($storedFile['variants']['source']['mimeType'])
            ->setFileSize($storedFile['variants']['source']['fileSize'])
            ->setWidth($storedFile['variants']['source']['width'])
            ->setHeight($storedFile['variants']['source']['height'])
            ->setVariants($storedFile['variants'])
            ->setMetadata([
                'articleResponsiveWebp' => true,
                'articleInlineMaxLongSide' => self::ARTICLE_INLINE_MAX_LONG_SIDE,
                'articleDisplayMaxLongSide' => self::ARTICLE_DISPLAY_MAX_LONG_SIDE,
                'articleCoverMaxLongSide' => self::ARTICLE_COVER_MAX_LONG_SIDE,
                'articleSourceMaxLongSide' => self::ARTICLE_SOURCE_MAX_LONG_SIDE,
                'originalMimeType' => $storedFile['originalMimeType'],
                'originalFileSize' => $storedFile['originalFileSize'],
                'sanitizedOriginalWidth' => $storedFile['sanitizedOriginalWidth'],
                'sanitizedOriginalHeight' => $storedFile['sanitizedOriginalHeight'],
            ]);

        return $media;
    }

    /**
     * @return array{
     *     title: string,
     *     altText: string,
     *     variants: array{
     *         source: array{path: string, mimeType: string, fileSize: int, width: int, height: int, generatedAt: string, formats: list<string>},
     *         thumb: array{webp: string, fileSize: int, width: int, height: int},
     *         mobile: array{webp: string, fileSize: int, width: int, height: int},
     *         medium: array{webp: string, fileSize: int, width: int, height: int},
     *         large: array{webp: string, fileSize: int, width: int, height: int}
     *     },
     *     originalMimeType: string,
     *     originalFileSize: int,
     *     sanitizedOriginalWidth: int,
     *     sanitizedOriginalHeight: int
     * }
     */
    private function storeArticleImage(UploadedFile $file, Article $article): array
    {
        $inspection = $this->imageUploadSecurity->inspect($file);

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
        $sourceAbsolutePath = $targetDirectory.'/'.$filename;
        $optimized = null;
        $sanitized = null;

        try {
            $sanitized = $this->imageMetadataSanitizer->sanitizePublicPath($publicPath);
            $optimized = $this->imageVariantGenerator->generateArticleResponsiveWebps(
                $publicPath,
                $publicPath,
                self::ARTICLE_INLINE_MAX_LONG_SIDE,
                self::ARTICLE_DISPLAY_MAX_LONG_SIDE,
                self::ARTICLE_COVER_MAX_LONG_SIDE,
                self::ARTICLE_SOURCE_MAX_LONG_SIDE,
                '/'.self::UPLOAD_DIRECTORY,
            );

            if (is_file($sourceAbsolutePath) && !@unlink($sourceAbsolutePath)) {
                $this->deleteGeneratedArticleVariants($optimized);

                throw new InvalidArgumentException('le fichier original Article n’a pas pu être supprimé après optimisation.');
            }
        } catch (\Throwable $exception) {
            if (is_file($sourceAbsolutePath)) {
                @unlink($sourceAbsolutePath);
            }

            throw $exception instanceof InvalidArgumentException
                ? $exception
                : new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        return [
            'title' => $this->mediaSeoTextService->titleForContext($article, MediaType::Image, ImageType::Standard),
            'altText' => $this->mediaSeoTextService->altTextForContext($article, MediaType::Image, ImageType::Standard),
            'variants' => $optimized,
            'originalMimeType' => $inspection['mimeType'],
            'originalFileSize' => $inspection['fileSize'],
            'sanitizedOriginalWidth' => $sanitized['width'],
            'sanitizedOriginalHeight' => $sanitized['height'],
        ];
    }

    /** @param array<array-key, mixed> $variants */
    private function deleteGeneratedArticleVariants(array $variants): void
    {
        $paths = [];
        array_walk_recursive($variants, static function (mixed $value) use (&$paths): void {
            if (is_string($value) && str_starts_with($value, '/'.self::UPLOAD_DIRECTORY.'/')) {
                $paths[$value] = true;
            }
        });

        $publicDirectory = rtrim((string) $this->parameterBag->get('kernel.project_dir'), '/').'/public';
        foreach (array_keys($paths) as $path) {
            $absolutePath = $publicDirectory.$path;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
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
    private function cleanupDetachedMedia(array $orphanCandidates, bool $addSuccessFlash = true): void
    {
        if ($orphanCandidates === []) {
            return;
        }

        $this->entityManager->flush();
        foreach ($orphanCandidates as $media) {
            if ($this->articleContentStillReferencesMedia($media)) {
                continue;
            }

            $result = $this->mediaDeletionService->deleteIfOrphan($media);
            if ($result['deleted'] && $addSuccessFlash) {
                $this->addFlash('success', sprintf('Ancien média #%d supprimé car il n’était plus utilisé.', $result['mediaId']));
            }
        }

        $this->entityManager->flush();
    }

    private function articleContentStillReferencesMedia(MediaAsset $media): bool
    {
        $mediaId = $media->getId();
        if ($mediaId === null) {
            return false;
        }

        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Article::class, 'a')
            ->andWhere('a.content LIKE :mediaToken')
            ->setParameter('mediaToken', '%'.sprintf('[[media:%d]]', $mediaId).'%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
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

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $digits = ltrim($value, '0');
        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) > strlen((string) PHP_INT_MAX)
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
