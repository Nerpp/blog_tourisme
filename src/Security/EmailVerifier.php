<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerifier
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $mailerFrom,
    ) {
    }

    public function sendEmailConfirmation(User $user): void
    {
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('User must be persisted before sending a confirmation email.');
        }

        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $userId,
            (string) $user->getEmail(),
            ['id' => $userId],
        );

        $email = (new TemplatedEmail())
            ->from($this->mailerFrom)
            ->to((string) $user->getEmail())
            ->subject('Confirmez votre adresse email Estela Explorations')
            ->htmlTemplate('registration/confirmation_email.html.twig')
            ->context([
                'signedUrl' => $signatureComponents->getSignedUrl(),
                'expiresAt' => $signatureComponents->getExpiresAt(),
                'expiresInMinutes' => max(1, (int) ceil(($signatureComponents->getExpiresAt()->getTimestamp() - time()) / 60)),
            ]);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            (string) $user->getEmail(),
        );

        $user->setIsVerified(true);
    }
}
