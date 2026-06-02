<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

final class AdminAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return null;
        }

        $user = $this->security->getUser();
        $message = $user instanceof User
            && in_array('ROLE_ADMIN', $user->getRoles(), true)
            && !$user->isVerified()
                ? 'Veuillez confirmer votre adresse email avant d’accéder à l’administration.'
                : 'Vous n’avez pas accès à l’administration.';

        $flashBag = $request->getSession()->getBag('flashes');
        if ($flashBag instanceof FlashBagInterface) {
            $flashBag->add('warning', $message);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }
}
