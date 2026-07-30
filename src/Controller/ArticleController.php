<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Category;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Service\Article\ArticleSeoMetadataProvider;
use App\Service\CommentSectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    private const int SUGGESTION_LIMIT = 8;
    private const int SUGGESTION_MIN_LENGTH = 2;
    private const int QUERY_MAX_LENGTH = 80;

    #[Route('/articles', name: 'app_article_index', methods: ['GET'])]
    public function index(Request $request, ArticleRepository $articleRepository, CategoryRepository $categoryRepository): Response
    {
        $query = $this->searchQuery($request);
        $categories = $categoryRepository->findUsedForPublicArticles();
        $categorySlug = $this->categorySlug($request, $categories);
        $articles = $articleRepository->findPublishedForListing($query, categorySlug: $categorySlug);

        return $this->render('article/index.html.twig', [
            'articles' => $articles,
            'article_count' => $articleRepository->countPublicArticles($query, $categorySlug),
            'categories' => $categories,
            'search_query' => $query,
            'selected_category_slug' => $categorySlug,
        ]);
    }

    #[Route('/articles/suggestions', name: 'app_article_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, ArticleRepository $articleRepository): JsonResponse
    {
        $query = $this->searchQuery($request);

        if (mb_strlen($query) < self::SUGGESTION_MIN_LENGTH) {
            return new JsonResponse(['suggestions' => []]);
        }

        /** @var list<array{title: string, url: string, type: string, meta: string}> $suggestions */
        $suggestions = array_map(
            fn (Article $article): array => [
                'title' => (string) $article->getTitle(),
                'url' => $this->generateUrl('app_article_show', ['slug' => (string) $article->getSlug()]),
                'type' => 'Article',
                'meta' => $article->getCategory()?->getName() ?? 'Article',
            ],
            $articleRepository->findPublishedSuggestions($query, self::SUGGESTION_LIMIT),
        );

        return new JsonResponse(['suggestions' => $suggestions]);
    }

    #[Route('/articles/{slug}', name: 'app_article_show', methods: ['GET'])]
    public function show(
        string $slug,
        Request $request,
        ArticleRepository $articleRepository,
        CommentSectionProvider $commentSectionProvider,
        ArticleSeoMetadataProvider $articleSeoMetadataProvider,
    ): Response
    {
        $article = $articleRepository->findPublishedBySlug($slug);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        return $this->render('article/show.html.twig', [
            'article' => $article,
            'article_seo' => $articleSeoMetadataProvider->provide($article),
            'return_context' => $this->resolveReturnContext($request, $article),
            'comment_section' => $commentSectionProvider->provide($article, $request, $this->getUser()),
        ]);
    }

    /** @return array{label: string, url: string}|null */
    private function resolveReturnContext(Request $request, Article $article): ?array
    {
        $query = $request->query->all();
        $sourceType = $query['from'] ?? null;
        $sourceSlug = $query['source'] ?? null;

        if (!is_string($sourceType) || !is_string($sourceSlug)) {
            return null;
        }

        $sourceSlug = trim(mb_substr($sourceSlug, 0, 180));
        if ($sourceSlug === '') {
            return null;
        }

        if ($sourceType === 'hike') {
            foreach ($article->getHikeLinks() as $link) {
                $hike = $link->getHikeDraft();
                if (
                    $hike?->getSlug() === $sourceSlug
                    && in_array($hike->getStatus()->value, ['finished', 'converted'], true)
                ) {
                    return [
                        'label' => sprintf('← Retour à la randonnée : %s', $hike->getTitle()),
                        'url' => $this->generateUrl('app_hike_show', ['slug' => $sourceSlug]),
                    ];
                }
            }
        }

        if ($sourceType === 'city_visit') {
            foreach ($article->getCityVisitLinks() as $link) {
                $cityVisit = $link->getCityVisitDraft();
                if (
                    $cityVisit?->getSlug() === $sourceSlug
                    && in_array($cityVisit->getStatus()->value, ['finished', 'converted'], true)
                ) {
                    return [
                        'label' => sprintf('← Retour à la visite : %s', $cityVisit->getTitle()),
                        'url' => $this->generateUrl('app_city_visit_show', ['slug' => $sourceSlug]),
                    ];
                }
            }
        }

        return null;
    }

    private function searchQuery(Request $request): string
    {
        $query = trim($request->query->getString('q'));

        return mb_substr($query, 0, self::QUERY_MAX_LENGTH);
    }

    /**
     * @param list<Category> $availableCategories
     */
    private function categorySlug(Request $request, array $availableCategories): ?string
    {
        $slug = trim(mb_strtolower($request->query->getString('category')));

        if ($slug === '') {
            return null;
        }

        $slug = mb_substr($slug, 0, 180);
        foreach ($availableCategories as $category) {
            if ($category->getSlug() === $slug) {
                return $slug;
            }
        }

        return null;
    }
}
