<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Form\CommentType;
use App\Repository\ArticleRepository;
use App\Repository\CommentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    #[Route('/articles/{slug}', name: 'app_article_show', methods: ['GET'])]
    public function show(string $slug, ArticleRepository $articleRepository, CommentRepository $commentRepository): Response
    {
        $article = $articleRepository->findOneBy(['slug' => $slug]);
        if ($article === null) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        $commentForm = $this->getUser() === null
            ? null
            : $this->createForm(CommentType::class, new Comment(), [
                'action' => $this->generateUrl('app_article_comment_create', ['slug' => $article->getSlug()]),
            ])->createView();

        return $this->render('article/show.html.twig', [
            'article' => $article,
            'comments' => $commentRepository->findApprovedForArticle($article),
            'comment_form' => $commentForm,
        ]);
    }
}
