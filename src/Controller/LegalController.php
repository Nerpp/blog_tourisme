<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    public function __construct(
        private readonly string $contactEmail,
        private readonly string $hostName,
        private readonly string $hostAddress,
    ) {
    }

    #[Route('/mentions-legales', name: 'app_legal_notice', methods: ['GET'])]
    public function legalNotice(): Response
    {
        return $this->render('legal/legal_notice.html.twig', $this->legalContext());
    }

    #[Route('/politique-de-confidentialite', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(): Response
    {
        return $this->render('legal/privacy_policy.html.twig', $this->legalContext());
    }

    /** @return array<string, string|bool> */
    private function legalContext(): array
    {
        $contactEmail = trim($this->contactEmail);
        $hostName = trim($this->hostName);
        $hostAddress = trim($this->hostAddress);

        return [
            'contact_email' => $contactEmail,
            'host_name' => $hostName,
            'host_address' => $hostAddress,
            'legal_notice_complete' => '' !== $hostName && '' !== $hostAddress,
            'privacy_policy_complete' => '' !== $contactEmail,
        ];
    }
}
