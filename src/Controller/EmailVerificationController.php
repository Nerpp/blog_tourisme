<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\InitialPasswordFormType;
use App\Form\ResendVerificationFormType;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Security\EmailVerificationResender;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class EmailVerificationController extends AbstractController
{
    private const GENERIC_RESEND_MESSAGE = 'Si un compte non vérifié correspond à cette adresse, un nouveau lien d’activation vient de lui être envoyé.';

    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET', 'POST'])]
    public function verifyEmail(
        Request $request,
        UserRepository $userRepository,
        EmailVerifier $emailVerifier,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ResetPasswordRequestRepository $resetPasswordRequestRepository,
    ): Response {
        $id = $request->query->get('id');
        $user = is_string($id) && ctype_digit($id) ? $userRepository->find((int) $id) : null;

        if (!$user instanceof User) {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou a expiré.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $emailVerifier->validateEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface) {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou a expiré.');

            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(InitialPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
                    $emailVerifier,
                    $form,
                    $passwordHasher,
                    $request,
                    $resetPasswordRequestRepository,
                    $user,
                ): void {
                    $entityManager->refresh($user, LockMode::PESSIMISTIC_WRITE);
                    $emailVerifier->validateEmailConfirmation($request, $user);

                    $plainPassword = $form->get('plainPassword')->getData();
                    $user
                        ->setPassword($passwordHasher->hashPassword(
                            $user,
                            is_string($plainPassword) ? $plainPassword : '',
                        ))
                        ->setIsVerified(true)
                        ->setEmailVerificationTokenHash(null);

                    $resetPasswordRequestRepository->removeRequests($user);
                });
            } catch (VerifyEmailExceptionInterface) {
                $this->addFlash('error', 'Le lien de confirmation est invalide ou a expiré.');

                return $this->redirectToRoute('app_login');
            }

            $this->addFlash('success', 'Votre adresse email est confirmée et votre mot de passe a été créé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/initial_password.html.twig', [
            'initial_password_form' => $form->createView(),
        ]);
    }

    #[Route('/verify/resend', name: 'app_verify_email_resend', methods: ['GET', 'POST'])]
    public function resend(
        Request $request,
        EmailVerificationResender $emailVerificationResender,
    ): Response {
        $form = $this->createForm(ResendVerificationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $emailVerificationResender->request(
                is_string($email) ? $email : '',
                $request->getClientIp(),
            );
            $this->addFlash('success', self::GENERIC_RESEND_MESSAGE);

            return $this->redirectToRoute('app_verify_email_resend');
        }

        return $this->render('registration/resend_verification.html.twig', [
            'resend_verification_form' => $form->createView(),
        ]);
    }
}
