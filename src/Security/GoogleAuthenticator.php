<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RouterInterface $router,
        private readonly TranslatorInterface $translator,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(new UserBadge($accessToken->getToken(), function () use ($client, $accessToken): User {
            $googleUser = $client->fetchUserFromToken($accessToken);
            if (!$googleUser instanceof GoogleUser) {
                throw new CustomUserMessageAuthenticationException('security.google.failed');
            }

            return $this->findOrCreateUser($googleUser);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($targetPath ?: $this->router->generate('app_profile'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $messageKey = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'security.google.failed_retry';
        $message = $this->translator->trans($messageKey, $exception->getMessageData(), 'security');

        $flashBag = $request->getSession()->getBag('flashes');
        if ($flashBag instanceof FlashBagInterface) {
            $flashBag->add('error', $message);
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function findOrCreateUser(GoogleUser $googleUser): User
    {
        $rawGoogleId = $googleUser->getId();
        $googleId = is_string($rawGoogleId) ? $rawGoogleId : '';
        $email = $googleUser->getEmail();
        if ($googleId === '' || $email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CustomUserMessageAuthenticationException('security.google.invalid_email');
        }

        $email = mb_strtolower($email);
        $emailVerified = $googleUser->getEmailVerified() === true;

        if (!$emailVerified) {
            throw new CustomUserMessageAuthenticationException('security.google.email_unverified');
        }

        $user = $this->userRepository->findOneByGoogleId($googleId);

        if (!$user instanceof User) {
            $user = $this->userRepository->findOneByEmail($email);
        }

        if (!$user instanceof User) {
            $user = new User();
            $user
                ->setEmail($email)
                ->setRoles(['ROLE_USER'])
                ->setDisplayName($this->displayNameFromGoogle($googleUser, $email))
                ->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(48))));

            $this->entityManager->persist($user);
        }

        if ($user->getGoogleId() !== null && $user->getGoogleId() !== $googleId) {
            throw new CustomUserMessageAuthenticationException('security.google.account_already_linked');
        }

        if ($user->getGoogleId() === null) {
            $user->setGoogleId($googleId);
        }

        $user->setIsVerified(true);


        $this->entityManager->flush();

        return $user;
    }

    private function displayNameFromGoogle(GoogleUser $googleUser, string $email): string
    {
        $data = $googleUser->toArray();
        $nameValue = $data['name'] ?? null;
        $name = is_string($nameValue) ? trim($nameValue) : '';

        return $name !== '' ? mb_substr($name, 0, 120) : mb_substr(strstr($email, '@', true) ?: $email, 0, 120);
    }
}
