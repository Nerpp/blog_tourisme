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
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return null;
        }

        if (preg_match('#^/admin/users/\d+/roles/admin/(?:grant|revoke)$#', $request->getPathInfo()) === 1) {
            return null;
        }

        $user = $this->security->getUser();
        $messageKey = $user instanceof User
            && $user->isAdmin()
            && !$user->isVerified()
                ? 'security.admin.email_verification_required'
                : 'security.admin.access_denied';

        $flashBag = $request->getSession()->getBag('flashes');
        if ($flashBag instanceof FlashBagInterface) {
            $flashBag->add('warning', $this->translator->trans($messageKey, domain: 'security'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }
}
