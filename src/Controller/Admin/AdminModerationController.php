<?php

namespace App\Controller\Admin;

use App\Repository\CommentReportRepository;
use App\Repository\CommentRepository;
use App\Repository\ModerationKeywordRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminModerationController extends AbstractController
{
    #[Route('/admin/comments', name: 'admin_comments_index', methods: ['GET'])]
    public function comments(CommentRepository $commentRepository): Response
    {
        return $this->render('admin/comments/index.html.twig', [
            'comments' => $commentRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }

    #[Route('/admin/comment-reports', name: 'admin_comment_reports_index', methods: ['GET'])]
    public function reports(CommentReportRepository $commentReportRepository): Response
    {
        return $this->render('admin/comments/reports.html.twig', [
            'reports' => $commentReportRepository->findBy([], ['createdAt' => 'DESC'], 50),
        ]);
    }

    #[Route('/admin/moderation-keywords', name: 'admin_moderation_keywords_index', methods: ['GET'])]
    public function keywords(ModerationKeywordRepository $moderationKeywordRepository): Response
    {
        return $this->render('admin/comments/keywords.html.twig', [
            'keywords' => $moderationKeywordRepository->findBy([], ['keyword' => 'ASC'], 100),
        ]);
    }
}
