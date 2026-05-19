<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\ContentEditVoter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminArticleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
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
    public function new(Request $request, CategoryRepository $categoryRepository): Response
    {
        $article = new Article();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_form', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateArticleFromRequest($article, $request)) {
                $article->setSlug($this->createUniqueSlug($article->getTitle() ?? 'article', Article::class));
                $user = $this->getUser();
                if ($user instanceof User) {
                    $article->setAuthor($user);
                }

                $this->entityManager->persist($article);
                $this->entityManager->flush();
                $this->addFlash('success', 'Article créé.');

                return $this->redirectToRoute('admin_articles_index');
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => $article,
            'categories' => $categoryRepository->findBy([], ['name' => 'ASC']),
            'status_options' => $this->statusLabels(),
            'title' => 'Nouvel article',
            'submit_label' => 'Créer l’article',
        ]);
    }

    #[Route('/admin/articles/{id}/edit', name: 'admin_articles_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Article $article, Request $request, CategoryRepository $categoryRepository): Response
    {
        $this->denyAccessUnlessGranted(ContentEditVoter::EDIT, $article);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_article_'.$article->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($this->updateArticleFromRequest($article, $request)) {
                $this->entityManager->flush();
                $this->addFlash('success', 'Article enregistré.');

                return $this->redirectToRoute('admin_articles_index');
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => $article,
            'categories' => $categoryRepository->findBy([], ['name' => 'ASC']),
            'status_options' => $this->statusLabels(),
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
        $title = trim($request->request->getString('title'));
        $content = trim($request->request->getString('content'));
        if ($title === '' || $content === '') {
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

        return true;
    }

    /** @param class-string $entityClass */
    private function createUniqueSlug(string $name, string $entityClass): string
    {
        $baseSlug = strtolower((string) $this->slugger->slug($name));
        $baseSlug = trim($baseSlug, '-') ?: 'contenu';
        $slug = $baseSlug;
        $suffix = 2;
        $repository = $this->entityManager->getRepository($entityClass);

        while ($repository->findOneBy(['slug' => $slug]) !== null) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
