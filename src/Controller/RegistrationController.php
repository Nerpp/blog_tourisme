<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\AvatarUploadService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        AvatarUploadService $avatarUploadService,
    ): Response {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_profile');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

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
                ->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Votre compte a ete cree. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registration_form' => $form->createView(),
        ]);
    }
}
