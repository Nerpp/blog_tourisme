<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Place;
use App\Enum\CategoryType;
use App\Repository\CategoryRepository;
use App\Security\Voter\AdminAccessVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminArticleCategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('/admin/article-categories', name: 'admin_article_categories_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/article_categories/index.html.twig', [
            'categories' => $this->categoryRepository->findArticleCategories(),
        ]);
    }

    #[Route('/admin/article-categories/new', name: 'admin_article_categories_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = (new Category())->setType(CategoryType::Article);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_category_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateCategoryFromRequest($category, $request)) {
                $this->entityManager->persist($category);
                $this->entityManager->flush();
                $this->addFlash('success', 'Catégorie créée.');

                return $this->redirectToRoute('admin_article_categories_index');
            }
        }

        return $this->renderCategoryForm($category, 'Nouvelle catégorie', 'Créer la catégorie');
    }

    #[Route('/admin/article-categories/{id}/edit', name: 'admin_article_categories_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Category $category, Request $request): Response
    {
        if ($category->getType() !== CategoryType::Article) {
            $this->addFlash('warning', 'Cette catégorie est partagée avec les lieux et ne peut pas être modifiée depuis la gestion des catégories d’articles.');

            return $this->redirectToRoute('admin_article_categories_index');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_category_'.$category->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateCategoryFromRequest($category, $request)) {
                $this->entityManager->flush();
                $this->addFlash('success', 'Catégorie enregistrée.');

                return $this->redirectToRoute('admin_article_categories_index');
            }
        }

        return $this->renderCategoryForm($category, 'Modifier la catégorie', 'Enregistrer');
    }

    #[Route('/admin/article-categories/{id}/delete', name: 'admin_article_categories_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Category $category, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_article_category_delete_'.$category->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $articleCount = $this->entityManager->getRepository(Article::class)->count(['category' => $category]);
        if ($articleCount > 0) {
            $this->addFlash('error', sprintf(
                'Cette catégorie ne peut pas être supprimée car elle est encore utilisée par %d article(s).',
                $articleCount,
            ));

            return $this->redirectToRoute('admin_article_categories_index');
        }

        $placeCount = $this->entityManager->getRepository(Place::class)->count(['category' => $category]);
        if ($placeCount > 0) {
            $this->addFlash('error', sprintf(
                'Cette catégorie ne peut pas être supprimée car elle est encore utilisée par %d lieu(x).',
                $placeCount,
            ));

            return $this->redirectToRoute('admin_article_categories_index');
        }

        if ($category->getType() !== CategoryType::Article) {
            $this->addFlash('error', 'Cette catégorie est partagée avec les lieux et ne peut pas être supprimée depuis la gestion des catégories d’articles.');

            return $this->redirectToRoute('admin_article_categories_index');
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'Catégorie supprimée.');

        return $this->redirectToRoute('admin_article_categories_index');
    }

    private function updateCategoryFromRequest(Category $category, Request $request): bool
    {
        $name = trim($request->request->getString('name'));
        $submittedSlug = trim($request->request->getString('slug'));
        $slug = $this->normalizeSlug($submittedSlug !== '' ? $submittedSlug : $name);
        $description = trim($request->request->getString('description'));

        $category
            ->setName($name)
            ->setSlug($slug)
            ->setDescription($description !== '' ? $description : null);

        if ($name === '' || $slug === '') {
            $this->addFlash('error', 'Le nom et le slug sont obligatoires.');

            return false;
        }

        if (mb_strlen($name) > 120 || mb_strlen($slug) > 180) {
            $this->addFlash('error', 'Le nom ou le slug est trop long.');

            return false;
        }

        $existingCategory = $this->categoryRepository->findOneBy(['slug' => $slug]);
        if ($existingCategory instanceof Category && $existingCategory->getId() !== $category->getId()) {
            $this->addFlash('error', 'Ce slug est déjà utilisé par une autre catégorie.');

            return false;
        }

        return true;
    }

    private function normalizeSlug(string $value): string
    {
        return trim(strtolower((string) $this->slugger->slug($value)), '-');
    }

    private function renderCategoryForm(Category $category, string $title, string $submitLabel): Response
    {
        return $this->render('admin/article_categories/form.html.twig', [
            'category' => $category,
            'title' => $title,
            'submit_label' => $submitLabel,
        ]);
    }
}
