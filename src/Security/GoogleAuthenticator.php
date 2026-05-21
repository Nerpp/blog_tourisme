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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RouterInterface $router,
    ) {
    }

    public function supports(Request $request): ?bool
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
                throw new CustomUserMessageAuthenticationException('La connexion Google a échoué.');
            }

            return $this->findOrCreateUser($googleUser);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($targetPath ?: $this->router->generate('app_profile'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('error', 'La connexion Google a échoué. Réessayez ou utilisez votre mot de passe.');

        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function findOrCreateUser(GoogleUser $googleUser): User
    {
        $googleId = (string) $googleUser->getId();
        $email = $googleUser->getEmail();
        if ($googleId === '' || $email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new CustomUserMessageAuthenticationException('Le compte Google ne fournit pas une adresse email valide.');
        }

        $email = mb_strtolower($email);
        $emailVerified = $googleUser->getEmailVerified() === true;
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
            throw new CustomUserMessageAuthenticationException('Ce compte email est déjà rattaché à un autre compte Google.');
        }

        if ($user->getGoogleId() === null) {
            $user->setGoogleId($googleId);
        }

        if ($emailVerified) {
            $user->setIsVerified(true);
        }

        $this->entityManager->flush();

        return $user;
    }

    private function displayNameFromGoogle(GoogleUser $googleUser, string $email): string
    {
        $data = $googleUser->toArray();
        $name = trim((string) ($data['name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 120) : mb_substr(strstr($email, '@', true) ?: $email, 0, 120);
    }
}
