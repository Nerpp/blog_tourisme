<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class EmailVerificationController extends AbstractController
{
    private const GENERIC_RESEND_MESSAGE = 'Si votre compte doit être vérifié, un nouvel email vient d’être envoyé.';

    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verifyEmail(
        Request $request,
        UserRepository $userRepository,
        EmailVerifier $emailVerifier,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $id = $request->query->get('id');
        $user = is_string($id) && ctype_digit($id) ? $userRepository->find((int) $id) : null;

        if (!$user instanceof User) {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou a expiré.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface) {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou a expiré.');

            return $this->redirectToRoute('app_login');
        }

        $entityManager->flush();
        $this->addFlash('success', 'Votre adresse email a bien été confirmée.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/verify/resend', name: 'app_verify_email_resend', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function resend(
        Request $request,
        EmailVerifier $emailVerifier,
        RateLimiterFactoryInterface $emailVerificationResendLimiter,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isVerified()) {
            $this->addFlash('success', self::GENERIC_RESEND_MESSAGE);

            return $this->redirectToRoute('app_profile');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('resend-email-verification', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $limit = $emailVerificationResendLimiter
                ->create($user->getId().'|'.($request->getClientIp() ?? 'unknown-ip'))
                ->consume();

            if ($limit->isAccepted()) {
                $emailVerifier->sendEmailConfirmation($user);
            }

            $this->addFlash('success', self::GENERIC_RESEND_MESSAGE);

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('registration/resend_verification.html.twig');
    }
}
