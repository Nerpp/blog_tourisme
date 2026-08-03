<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class EmailVerificationResender
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailVerifier $emailVerifier,
        private readonly RateLimiterFactoryInterface $emailVerificationResendLimiter,
    ) {
    }

    public function request(string $email, ?string $clientIp): void
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $emailLimit = $this->emailVerificationResendLimiter
            ->create(hash('sha256', "activation-resend-email\0".$normalizedEmail))
            ->consume();
        $clientLimit = $this->emailVerificationResendLimiter
            ->create(hash('sha256', "activation-resend-client\0".($clientIp ?? 'unknown-ip')))
            ->consume();

        if (!$emailLimit->isAccepted() || !$clientLimit->isAccepted()) {
            return;
        }

        $user = $this->userRepository->findOneByEmail($normalizedEmail);
        if (!$user instanceof User || $user->isVerified() || $user->isBanned()) {
            return;
        }

        try {
            $this->emailVerifier->sendEmailConfirmation($user);
        } catch (TransportExceptionInterface) {
            // Keep the public response indistinguishable when delivery fails.
        }
    }
}
