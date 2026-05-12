<?php

namespace App\Controller\Admin;

use App\Repository\CommentReportRepository;
use App\Repository\ModerationKeywordRepository;
use App\Security\Voter\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminModerationController extends AbstractController
{
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
