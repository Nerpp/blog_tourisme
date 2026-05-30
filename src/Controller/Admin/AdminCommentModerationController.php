<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentReportStatus;
use App\Enum\CommentStatus;
use App\Repository\CommentRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\AdminModerationVoter;
use App\Service\CommentDeletionService;
use App\Service\CommentModerationAdminService;
use App\Service\CommentReplyNotificationService;
use App\Service\ModerationActionLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminCommentModerationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentModerationAdminService $moderationService,
        private readonly ModerationActionLogger $moderationLogger,
    ) {
    }

    #[Route('/admin/comments', name: 'admin_comments_index', methods: ['GET'])]
    public function index(Request $request, CommentRepository $commentRepository): Response
    {
        return $this->renderComments($commentRepository, $request->query->get('filter'));
    }

    #[Route('/admin/comments/pending', name: 'admin_comments_pending', methods: ['GET'])]
    public function pending(CommentRepository $commentRepository): Response
    {
        return $this->renderComments($commentRepository, 'pending');
    }

    #[Route('/admin/comments/reported', name: 'admin_comments_reported', methods: ['GET'])]
    public function reported(CommentRepository $commentRepository): Response
    {
        return $this->renderComments($commentRepository, 'reported');
    }

    #[Route('/admin/comments/{id}/approve', name: 'admin_comments_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(
        Comment $comment,
        Request $request,
        CommentReplyNotificationService $notificationService,
    ): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_APPROVE, $comment);
        $this->validateCommentToken($comment, $request);
        $wasApproved = $comment->getStatus() === CommentStatus::Approved;
        $admin = $this->getAdminUser();
        $this->moderationService->approve($comment, $admin);
        if (!$wasApproved) {
            $notificationService->createForApprovedComment($comment);
        }
        $this->moderationLogger->log('comment.approve', $admin, 'comment', $comment->getId(), null, $request, $comment->getAuthor());
        $this->entityManager->flush();
        $this->addFlash('success', 'Commentaire accepté.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/reject', name: 'admin_comments_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Comment $comment, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_REJECT, $comment);
        $this->validateCommentToken($comment, $request);
        $admin = $this->getAdminUser();
        $reason = $request->request->getString('reason');

        try {
            $this->moderationService->reject($comment, $admin, $reason);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectBackToComments($request);
        }

        $this->moderationLogger->log('comment.reject', $admin, 'comment', $comment->getId(), $reason, $request, $comment->getAuthor());
        $this->entityManager->flush();
        $this->addFlash('warning', 'Commentaire refusé.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/delete', name: 'admin_comments_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Comment $comment, Request $request, CommentDeletionService $deletionService): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_DELETE, $comment);
        $this->validateCommentToken($comment, $request);
        $admin = $this->getAdminUser();
        $commentId = $comment->getId();
        $author = $comment->getAuthor();
        $this->moderationLogger->log('comment.delete', $admin, 'comment', $commentId, null, $request, $author);
        $deletionService->deletePhysically($comment);
        $this->entityManager->flush();
        $this->addFlash('success', 'Commentaire supprimé définitivement.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/spam', name: 'admin_comments_spam', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function spam(Comment $comment, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_SPAM, $comment);
        $this->validateCommentToken($comment, $request);
        $admin = $this->getAdminUser();
        $reason = $request->request->getString('reason');
        $this->moderationService->markAsSpam($comment, $admin, $reason);
        $this->moderationLogger->log('comment.spam', $admin, 'comment', $comment->getId(), $reason, $request, $comment->getAuthor());
        $this->entityManager->flush();
        $this->addFlash('warning', 'Commentaire marqué comme spam.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/hide', name: 'admin_comments_hide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function hide(Comment $comment, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_SPAM, $comment);
        $this->validateCommentToken($comment, $request);
        $admin = $this->getAdminUser();
        $reason = $request->request->getString('reason');
        $this->moderationService->hide($comment, $admin, $reason);
        $this->moderationLogger->log('comment.hide', $admin, 'comment', $comment->getId(), $reason, $request, $comment->getAuthor());
        $this->entityManager->flush();
        $this->addFlash('warning', 'Commentaire masqué.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/restore', name: 'admin_comments_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(Comment $comment, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_RESTORE, $comment);
        $this->validateCommentToken($comment, $request);
        if ($comment->getStatus() !== CommentStatus::Spam) {
            $this->addFlash('warning', 'Seuls les commentaires masqués peuvent être restaurés.');

            return $this->redirectBackToComments($request);
        }

        $admin = $this->getAdminUser();
        $this->moderationService->restore($comment, $admin);
        $this->moderationLogger->log('comment.restore', $admin, 'comment', $comment->getId(), null, $request, $comment->getAuthor());
        $this->entityManager->flush();
        $this->addFlash('success', 'Commentaire restauré.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/reports/review', name: 'admin_comments_reports_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reviewReports(Comment $comment, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_REPORT_REVIEW, $comment);
        $this->validateCommentToken($comment, $request);
        $admin = $this->getAdminUser();
        $now = new DateTimeImmutable();

        foreach ($comment->getReports() as $report) {
            if ($report->getStatus() !== CommentReportStatus::Pending) {
                continue;
            }

            $report
                ->setStatus(CommentReportStatus::Reviewed)
                ->setReviewedBy($admin)
                ->setReviewedAt($now);
        }

        $comment->setReportedCount(0);
        $this->moderationLogger->log('comment.reports.review', $admin, 'comment', $comment->getId(), null, $request, $comment->getAuthor());
        $this->entityManager->flush();
        $this->addFlash('success', 'Signalements marqués comme traités.');

        return $this->redirectBackToComments($request);
    }

    private function renderComments(CommentRepository $commentRepository, ?string $requestedFilter): Response
    {
        $this->denyAccessUnlessGranted(AdminModerationVoter::COMMENT_MODERATE);

        $filter = $requestedFilter;
        if ($filter === null || $filter === '') {
            $filter = $commentRepository->countReportedForModeration() > 0 ? 'reported' : 'recent';
        }

        $filter = in_array($filter, ['reported', 'recent', 'approved', 'hidden', 'all', 'pending'], true) ? $filter : 'recent';

        $comments = match ($filter) {
            'reported' => $commentRepository->findReportedForModeration(),
            'approved' => $commentRepository->findApprovedForModeration(),
            'hidden' => $commentRepository->findSpam(),
            'all' => $commentRepository->findAllForModeration(),
            'pending' => $commentRepository->findPendingForModeration(),
            default => $commentRepository->findRecentForModeration(),
        };

        return $this->render('admin/comments/index.html.twig', [
            'comments' => $comments,
            'current_filter' => $filter,
            'status_labels' => $this->statusLabels(),
        ]);
    }

    private function validateCommentToken(Comment $comment, Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_comment_action_'.$comment->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function getAdminUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function redirectBackToComments(Request $request): RedirectResponse
    {
        $returnUrl = $request->request->getString('returnUrl');
        if (str_starts_with($returnUrl, '/admin/comments') && !str_starts_with($returnUrl, '//')) {
            return new RedirectResponse($returnUrl);
        }

        return $this->redirectToRoute('admin_comments_index');
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            CommentStatus::Pending->value => 'En attente',
            CommentStatus::Approved->value => 'Approuvé',
            CommentStatus::Rejected->value => 'Refusé',
            CommentStatus::Spam->value => 'Masqué',
            CommentStatus::Deleted->value => 'Supprimé',
        ];
    }
}
