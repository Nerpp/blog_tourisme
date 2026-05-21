<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileFormType;
use App\Service\AvatarUploadService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        AvatarUploadService $avatarUploadService,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST') && $request->request->get('_profile_action') === 'delete_avatar') {
            if (!$this->isCsrfTokenValid('delete_avatar', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $avatarUploadService->delete($user->getAvatarPath());
            $user->setAvatarPath(null);
            $entityManager->flush();

            $this->addFlash('success', 'Votre avatar a été supprimé.');

            return $this->redirectToRoute('app_profile');
        }

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $avatarFile = $form->get('avatarFile')->getData();

            if ($avatarFile instanceof UploadedFile) {
                $previousAvatarPath = $user->getAvatarPath();

                try {
                    $user->setAvatarPath($avatarUploadService->upload($avatarFile));
                    $avatarUploadService->delete($previousAvatarPath);
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    $form->get('avatarFile')->addError(new FormError($exception->getMessage()));

                    return $this->render('profile/index.html.twig', [
                        'profile_form' => $form->createView(),
                    ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'Votre profil a été mis à jour.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'profile_form' => $form->createView(),
        ]);
    }
}
