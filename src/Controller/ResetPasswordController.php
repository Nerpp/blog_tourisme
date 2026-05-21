<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    private const GENERIC_RESET_MESSAGE = 'Si un compte existe avec cette adresse email, un lien de réinitialisation a été envoyé.';

    #[Route('/reset-password', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        ResetPasswordHelperInterface $resetPasswordHelper,
        MailerInterface $mailer,
        RateLimiterFactoryInterface $passwordResetLimiter,
        #[Autowire('%env(MAILER_FROM)%')]
        string $mailerFrom,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();
            $limit = $passwordResetLimiter
                ->create(hash('sha256', mb_strtolower(trim($email)).'|'.($request->getClientIp() ?? 'unknown-ip')))
                ->consume();

            if (!$limit->isAccepted()) {
                $this->addFlash('success', self::GENERIC_RESET_MESSAGE);

                return $this->redirectToRoute('app_check_email');
            }

            $user = $userRepository->findOneByEmail($email);

            if ($user instanceof User) {
                $this->sendPasswordResetEmail($user, $resetPasswordHelper, $mailer, $mailerFrom);
            }

            $this->addFlash('success', self::GENERIC_RESET_MESSAGE);

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', [
            'request_form' => $form->createView(),
        ]);
    }

    #[Route('/reset-password/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(ResetPasswordHelperInterface $resetPasswordHelper): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            'reset_token_lifetime' => $resetPasswordHelper->getTokenLifetime(),
            'generic_message' => self::GENERIC_RESET_MESSAGE,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_reset_password', defaults: ['token' => null], methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        ?string $token,
        ResetPasswordHelperInterface $resetPasswordHelper,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($token !== null) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if ($token === null) {
            $this->addFlash('error', 'Le lien de réinitialisation est invalide ou a expiré.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            $user = $resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', 'Le lien de réinitialisation est invalide ou a expiré.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        if (!$user instanceof User) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', 'Le lien de réinitialisation est invalide ou a expiré.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $resetPasswordHelper->removeResetRequest($token);
            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $entityManager->flush();
            $this->cleanSessionAfterReset();
            $this->addFlash('success', 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'reset_form' => $form->createView(),
        ]);
    }

    private function sendPasswordResetEmail(
        User $user,
        ResetPasswordHelperInterface $resetPasswordHelper,
        MailerInterface $mailer,
        string $mailerFrom,
    ): void {
        try {
            $resetToken = $resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from($mailerFrom)
            ->to((string) $user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'reset_token' => $resetToken,
                'token_lifetime_minutes' => intdiv($resetPasswordHelper->getTokenLifetime(), 60),
            ]);

        $mailer->send($email);
    }
}
