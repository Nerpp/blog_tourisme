<?php

namespace App\Controller;

use App\Service\Seo\PublicUrlGenerator;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleController extends AbstractController
{
    #[Route('/connect/google', name: 'connect_google_start', methods: ['GET'])]
    public function connect(ClientRegistry $clientRegistry, PublicUrlGenerator $publicUrlGenerator): RedirectResponse
    {
        return $publicUrlGenerator->withConfiguredRouterContext(
            fn (): RedirectResponse => $clientRegistry
                ->getClient('google')
                ->redirect(['openid', 'email', 'profile'], []),
        );
    }

    #[Route('/connect/google/check', name: 'connect_google_check', methods: ['GET'])]
    public function check(): Response
    {
        return $this->redirectToRoute('app_login');
    }
}
