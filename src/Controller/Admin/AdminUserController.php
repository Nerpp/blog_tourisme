<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Exception\UserRoleManagementException;
use App\Repository\AdminRoleAuditRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AdminAccessVoter;
use App\Security\Voter\UserRoleManagementVoter;
use App\Service\CommentModerationAdminService;
use App\Service\ModerationActionLogger;
use App\Service\UserRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ACCESS)]
final class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentModerationAdminService $moderationService,
        private readonly ModerationActionLogger $moderationLogger,
        private readonly UserRoleManager $roleManager,
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
    public function show(User $user, AdminRoleAuditRepository $auditRepository): Response
    {
        return $this->render('admin/users/show.html.twig', [
            'user_account' => $user,
            'recent_comments' => $user->getComments()->slice(0, 12),
            'warnings' => $user->getModerationWarnings(),
            'role_audits' => $auditRepository->findRecentForUser($user),
        ]);
    }

    #[Route('/admin/users/{id}/roles/admin/grant', name: 'admin_users_role_admin_grant', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function grantAdmin(User $user, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserRoleManagementVoter::MANAGE, $user);
        if (!$this->isCsrfTokenValid('admin_user_role_grant_'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $changed = $this->roleManager->grantAdminFromWeb($user, $this->getAdminUser());
            if ($changed) {
                $this->roleManager->flush();
            }
        } catch (UserRoleManagementException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $this->addFlash($changed ? 'success' : 'info', $changed
            ? 'L’utilisateur dispose désormais de l’accès administrateur.'
            : 'Le rôle administrateur était déjà présent.');

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }

    #[Route('/admin/users/{id}/roles/admin/revoke', name: 'admin_users_role_admin_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revokeAdmin(User $user, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserRoleManagementVoter::MANAGE, $user);
        if (!$this->isCsrfTokenValid('admin_user_role_revoke_'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $changed = $this->roleManager->revokeAdminFromWeb($user, $this->getAdminUser());
            if ($changed) {
                $this->roleManager->flush();
            }
        } catch (UserRoleManagementException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $this->addFlash($changed ? 'success' : 'info', $changed
            ? 'L’accès administrateur a été retiré.'
            : 'Cet utilisateur ne possédait pas le rôle administrateur.');

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }

    #[Route('/admin/users/{id}/ban', name: 'admin_users_ban', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ban(User $user, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_user_ban_'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $reason = trim($request->request->getString('reason')) ?: 'Bannissement manuel par l’administration.';
        if ($user->isAdmin()) {
            $this->addFlash('warning', 'Un administrateur ne peut pas être banni depuis cette interface.');

            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $this->moderationService->banUser($user, $reason);
        $this->moderationLogger->log('user.ban', $this->getAdminUser(), 'user', $user->getId(), $reason, $request, $user);
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
        $this->moderationLogger->log('user.unban', $this->getAdminUser(), 'user', $user->getId(), null, $request, $user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Utilisateur débanni.');

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }

    private function getAdminUser(): User
    {
        $admin = $this->getUser();
        if (!$admin instanceof User) {
            throw $this->createAccessDeniedException('Administrateur authentifié requis.');
        }

        return $admin;
    }
}
