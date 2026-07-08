<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Service\AvatarUploadService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    private const GENERIC_CONFIRMATION_MESSAGE = 'Si ce compte doit être vérifié, un email de confirmation vient d’être envoyé.';

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        AvatarUploadService $avatarUploadService,
        EmailVerifier $emailVerifier,
        RateLimiterFactoryInterface $emailVerificationResendLimiter,
    ): Response {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_profile');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        $submittedRegistration = $request->request->all($form->getName());
        $hasValidCsrfToken = $this->isCsrfTokenValid(
            $form->getName(),
            $this->stringOrEmpty($submittedRegistration['_token'] ?? null),
        );

        if ($form->isSubmitted() && $hasValidCsrfToken) {
            $existingUser = $userRepository->findOneByEmail($this->stringOrEmpty($form->get('email')->getData()));
            if ($existingUser instanceof User) {
                if (!$existingUser->isVerified()) {
                    $limit = $emailVerificationResendLimiter
                        ->create($existingUser->getId().'|'.($request->getClientIp() ?? 'unknown-ip'))
                        ->consume();

                    if ($limit->isAccepted()) {
                        $this->tryToSendEmailConfirmation($emailVerifier, $existingUser);
                    }
                }

                $this->addFlash('success', self::GENERIC_CONFIRMATION_MESSAGE);

                return $this->redirectToRoute('app_login');
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $avatarFile = $form->get('avatarFile')->getData();
            if ($avatarFile instanceof UploadedFile) {
                try {
                    $user->setAvatarPath($avatarUploadService->upload($avatarFile));
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    $form->get('avatarFile')->addError(new FormError($exception->getMessage()));

                    return $this->render('registration/register.html.twig', [
                        'registration_form' => $form->createView(),
                    ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }
            }

            $user
                ->setRoles(['ROLE_USER'])
                ->setIsVerified(false)
                ->setPassword($passwordHasher->hashPassword($user, $this->stringOrEmpty($form->get('plainPassword')->getData())));

            $entityManager->persist($user);
            $entityManager->flush();

            if ($this->tryToSendEmailConfirmation($emailVerifier, $user)) {
                $this->addFlash('success', 'Votre compte a été créé. Vérifiez votre adresse email pour activer votre compte.');
            } else {
                $this->addFlash('error', 'Votre compte a été créé, mais l’email de confirmation n’a pas pu être envoyé. Reconnectez-vous pour demander un nouvel envoi.');
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registration_form' => $form->createView(),
        ]);
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function tryToSendEmailConfirmation(EmailVerifier $emailVerifier, User $user): bool
    {
        try {
            $emailVerifier->sendEmailConfirmation($user);

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }
}
