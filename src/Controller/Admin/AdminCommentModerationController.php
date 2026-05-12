<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use App\Repository\CommentRepository;
use App\Service\CommentModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminCommentModerationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentModerationService $moderationService,
    ) {
    }

    #[Route('/admin/comments', name: 'admin_comments_index', methods: ['GET'])]
    public function index(Request $request, CommentRepository $commentRepository): Response
    {
        return $this->renderComments($commentRepository, $request->query->getString('filter', 'all'));
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
    public function approve(Comment $comment, Request $request): RedirectResponse
    {
        $this->validateCommentToken($comment, $request);
        $this->moderationService->approve($comment, $this->getAdminUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'Commentaire accepté.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/reject', name: 'admin_comments_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Comment $comment, Request $request): RedirectResponse
    {
        $this->validateCommentToken($comment, $request);
        $this->moderationService->reject($comment, $this->getAdminUser());
        $this->entityManager->flush();
        $this->addFlash('warning', 'Commentaire refusé.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/delete', name: 'admin_comments_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Comment $comment, Request $request): RedirectResponse
    {
        $this->validateCommentToken($comment, $request);
        $this->moderationService->deleteByModeration($comment, $this->getAdminUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'Commentaire supprimé.');

        return $this->redirectBackToComments($request);
    }

    #[Route('/admin/comments/{id}/spam', name: 'admin_comments_spam', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function spam(Comment $comment, Request $request): RedirectResponse
    {
        $this->validateCommentToken($comment, $request);
        $this->moderationService->markSpam($comment, $this->getAdminUser());
        $this->entityManager->flush();
        $this->addFlash('warning', 'Commentaire marqué comme spam.');

        return $this->redirectBackToComments($request);
    }

    private function renderComments(CommentRepository $commentRepository, string $filter): Response
    {
        $filter = in_array($filter, ['all', 'pending', 'approved', 'reported', 'spam', 'deleted'], true) ? $filter : 'all';

        $comments = match ($filter) {
            'pending' => $commentRepository->findBy(['status' => CommentStatus::Pending], ['createdAt' => 'ASC'], 100),
            'approved' => $commentRepository->findBy(['status' => CommentStatus::Approved], ['createdAt' => 'DESC'], 100),
            'reported' => $commentRepository->findReportedForModeration(),
            'spam' => $commentRepository->findBy(['status' => CommentStatus::Spam], ['spamScore' => 'DESC', 'createdAt' => 'DESC'], 100),
            'deleted' => $commentRepository->findBy(['status' => CommentStatus::Deleted], ['createdAt' => 'DESC'], 100),
            default => $commentRepository->findBy([], ['createdAt' => 'DESC'], 100),
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
            CommentStatus::Spam->value => 'Spam',
            CommentStatus::Deleted->value => 'Supprimé',
        ];
    }
}
