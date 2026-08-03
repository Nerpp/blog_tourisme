<?php

namespace App\Security;

use App\Entity\User;
use App\Service\Seo\PublicUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerifier
{
    private const PURPOSE = 'initial-password';

    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        private readonly PublicUrlGenerator $publicUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
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

        $signatureComponents = $this->publicUrlGenerator->withConfiguredRouterContext(
            fn () => $this->verifyEmailHelper->generateSignature(
                'app_verify_email',
                (string) $userId,
                (string) $user->getEmail(),
                [
                    'id' => $userId,
                    'purpose' => self::PURPOSE,
                    'nonce' => bin2hex(random_bytes(16)),
                ],
            ),
        );
        $signedUrl = $signatureComponents->getSignedUrl();
        $user->setEmailVerificationTokenHash(hash('sha256', $this->signatureFromUrl($signedUrl)));
        $this->entityManager->flush();

        $email = (new TemplatedEmail())
            ->from($this->mailerFrom)
            ->to((string) $user->getEmail())
            ->subject('Confirmez votre adresse email Estela Explorations')
            ->htmlTemplate('registration/confirmation_email.html.twig')
            ->context([
                'signedUrl' => $signedUrl,
                'expiresAt' => $signatureComponents->getExpiresAt(),
                'expiresInMinutes' => max(1, (int) ceil(($signatureComponents->getExpiresAt()->getTimestamp() - time()) / 60)),
            ]);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function validateEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            (string) $user->getEmail(),
        );

        $signature = $request->query->getString('signature');
        $storedTokenHash = $user->getEmailVerificationTokenHash();
        if (
            $user->isVerified()
            || $request->query->getString('purpose') !== self::PURPOSE
            || $signature === ''
            || $storedTokenHash === null
            || !hash_equals($storedTokenHash, hash('sha256', $signature))
        ) {
            throw new InvalidSignatureException();
        }
    }

    private function signatureFromUrl(string $signedUrl): string
    {
        $query = parse_url($signedUrl, PHP_URL_QUERY);
        if (!is_string($query)) {
            throw new \LogicException('The generated email verification URL has no query string.');
        }

        parse_str($query, $parameters);
        $signature = $parameters['signature'] ?? null;
        if (!is_string($signature) || $signature === '') {
            throw new \LogicException('The generated email verification URL has no signature.');
        }

        return $signature;
    }
}
