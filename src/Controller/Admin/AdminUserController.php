<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\CommentModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentModerationService $moderationService,
    ) {
    }

    #[Route('/admin/users', name: 'admin_users_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'users' => $userRepository->findBy([], ['createdAt' => 'DESC'], 100),
        ]);
    }

    #[Route('/admin/users/{id}', name: 'admin_users_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('admin/users/show.html.twig', [
            'user_account' => $user,
            'recent_comments' => $user->getComments()->slice(0, 12),
            'warnings' => $user->getModerationWarnings(),
        ]);
    }

    #[Route('/admin/users/{id}/ban', name: 'admin_users_ban', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ban(User $user, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_user_ban_'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $reason = trim($request->request->getString('reason')) ?: 'Bannissement manuel par l’administration.';
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $this->addFlash('warning', 'Un administrateur ne peut pas être banni depuis cette interface.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $this->moderationService->banUser($user, $reason);
        $this->entityManager->flush();
        $this->addFlash('warning', 'Utilisateur banni.');

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }

    #[Route('/admin/users/{id}/unban', name: 'admin_users_unban', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unban(User $user, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_user_unban_'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->moderationService->unbanUser($user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Utilisateur débanni.');

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }
}
