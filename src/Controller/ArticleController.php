<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\User;
use App\Form\CommentType;
use App\Repository\ArticleRepository;
use App\Repository\CommentRepository;
use App\Service\CommentReactionViewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    #[Route('/articles', name: 'app_article_index', methods: ['GET'])]
    public function index(ArticleRepository $articleRepository): Response
    {
        return $this->render('article/index.html.twig', [
            'articles' => $articleRepository->findPublished(),
        ]);
    }

    #[Route('/articles/{slug}', name: 'app_article_show', methods: ['GET'])]
    public function show(
        string $slug,
        ArticleRepository $articleRepository,
        CommentRepository $commentRepository,
        CommentReactionViewService $reactionViewService,
    ): Response
    {
        $article = $articleRepository->findPublishedBySlug($slug);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        $commentForm = $this->getUser() === null
            ? null
            : $this->createForm(CommentType::class, new Comment(), [
                'action' => $this->generateUrl('app_article_comment_create', ['slug' => $article->getSlug()]),
                'method' => 'POST',
            ])->createView();

        $viewer = $this->getUser();
        $comments = $commentRepository->findApprovedForArticle($article, $viewer instanceof User ? $viewer : null);
        $reactionContext = $reactionViewService->buildContext($comments, $viewer instanceof User ? $viewer : null);

        return $this->render('article/show.html.twig', [
            'article' => $article,
            'comments' => $comments,
            'comment_form' => $commentForm,
            'comment_like_counts' => $reactionContext['like_counts'],
            'liked_comment_ids' => $reactionContext['liked_comment_ids'],
        ]);
    }
}
