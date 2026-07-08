<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\GoogleAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\GoogleUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class GoogleAuthenticatorTest extends TestCase
{
    public function testSupportsOnlyGoogleCheckRoute(): void
    {
        $authenticator = $this->authenticator();

        self::assertTrue($authenticator->supports(new Request(attributes: ['_route' => 'connect_google_check'])));
        self::assertFalse($authenticator->supports(new Request(attributes: ['_route' => 'app_login'])));
    }

    public function testRejectsUnverifiedGoogleEmailWithUserMessage(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Votre email doit être vérifié par Google.');

        $this->invokeFindOrCreateUser($this->authenticator(), new GoogleUser([
            'sub' => 'google-1',
            'email' => 'user@example.test',
            'email_verified' => false,
            'name' => 'Google User',
        ]));
    }

    public function testRejectsMissingOrInvalidEmail(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Le compte Google ne fournit pas une adresse email valide.');

        $this->invokeFindOrCreateUser($this->authenticator(), new GoogleUser([
            'sub' => 'google-1',
            'email_verified' => true,
            'name' => 'Google User',
        ]));
    }

    public function testRejectsStructuredGoogleIdentifier(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Le compte Google ne fournit pas une adresse email valide.');

        $this->invokeFindOrCreateUser($this->authenticator(), new GoogleUser([
            'sub' => ['google-1'],
            'email' => 'user@example.test',
            'email_verified' => true,
            'name' => 'Google User',
        ]));
    }

    public function testStructuredGoogleNameFallsBackToEmailPrefix(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByGoogleId')->willReturn(null);
        $repository->method('findOneByEmail')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $entityManager->expects(self::once())->method('flush');

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $user = $this->invokeFindOrCreateUser(
            $this->authenticator(
                userRepository: $repository,
                entityManager: $entityManager,
                passwordHasher: $passwordHasher,
            ),
            new GoogleUser([
                'sub' => 'google-structured-name',
                'email' => 'Fallback.Name@example.test',
                'email_verified' => true,
                'name' => ['Structured Name'],
            ]),
        );

        self::assertSame('fallback.name', $user->getDisplayName());
        self::assertSame('google-structured-name', $user->getGoogleId());
    }

    public function testLinksExistingEmailAndMarksUserVerified(): void
    {
        $user = (new User())
            ->setEmail('existing@example.test')
            ->setDisplayName('Existing User')
            ->setPassword('password')
            ->setIsVerified(false);
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())->method('findOneByGoogleId')->with('google-1')->willReturn(null);
        $repository->expects(self::once())->method('findOneByEmail')->with('existing@example.test')->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $resolvedUser = $this->invokeFindOrCreateUser(
            $this->authenticator(userRepository: $repository, entityManager: $entityManager),
            new GoogleUser([
                'sub' => 'google-1',
                'email' => 'Existing@Example.Test',
                'email_verified' => true,
                'name' => 'Existing User',
            ]),
        );

        self::assertSame($user, $resolvedUser);
        self::assertSame('google-1', $user->getGoogleId());
        self::assertTrue($user->isVerified());
    }

    public function testCreatesUserFromVerifiedGoogleProfile(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByGoogleId')->willReturn(null);
        $repository->method('findOneByEmail')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(User::class));
        $entityManager->expects(self::once())->method('flush');

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $user = $this->invokeFindOrCreateUser(
            $this->authenticator(
                userRepository: $repository,
                entityManager: $entityManager,
                passwordHasher: $passwordHasher,
            ),
            new GoogleUser([
                'sub' => 'google-2',
                'email' => 'New@Example.Test',
                'email_verified' => true,
                'name' => 'New Google User',
            ]),
        );

        self::assertSame('new@example.test', $user->getEmail());
        self::assertSame('New Google User', $user->getDisplayName());
        self::assertSame('google-2', $user->getGoogleId());
        self::assertSame('hashed-password', $user->getPassword());
        self::assertTrue($user->isVerified());
    }

    public function testRejectsExistingEmailLinkedToDifferentGoogleAccount(): void
    {
        $user = (new User())
            ->setEmail('existing@example.test')
            ->setDisplayName('Existing User')
            ->setPassword('password')
            ->setGoogleId('other-google-id');
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByGoogleId')->willReturn(null);
        $repository->method('findOneByEmail')->willReturn($user);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Ce compte email est déjà rattaché à un autre compte Google.');

        $this->invokeFindOrCreateUser(
            $this->authenticator(userRepository: $repository),
            new GoogleUser([
                'sub' => 'google-1',
                'email' => 'existing@example.test',
                'email_verified' => true,
                'name' => 'Existing User',
            ]),
        );
    }

    public function testAuthenticationFailureAddsSafeFlashMessageAndRedirectsToLogin(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_login')->willReturn('/login');
        $authenticator = $this->authenticator(router: $router);
        $request = new Request();
        $session = new Session(new MockArraySessionStorage(), null, new FlashBag());
        $request->setSession($session);

        $response = $authenticator->onAuthenticationFailure(
            $request,
            new AuthenticationException('Sensitive low-level detail'),
        );

        self::assertSame('/login', $response->headers->get('Location'));
        self::assertSame(
            ['La connexion Google a échoué. Réessayez ou utilisez votre mot de passe.'],
            $session->getFlashBag()->peek('error'),
        );
    }

    public function testAuthenticationSuccessUsesSavedTargetOrProfileFallback(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_profile')->willReturn('/profile');
        $authenticator = $this->authenticator(router: $router);
        $token = $this->createStub(TokenInterface::class);

        $targetRequest = new Request();
        $targetSession = new Session(new MockArraySessionStorage());
        $targetSession->set('_security.main.target_path', '/destination-privee');
        $targetRequest->setSession($targetSession);

        $defaultRequest = new Request();
        $defaultRequest->setSession(new Session(new MockArraySessionStorage()));

        self::assertSame(
            '/destination-privee',
            $authenticator->onAuthenticationSuccess($targetRequest, $token, 'main')->headers->get('Location'),
        );
        self::assertSame(
            '/profile',
            $authenticator->onAuthenticationSuccess($defaultRequest, $token, 'main')->headers->get('Location'),
        );
    }

    public function testAuthenticationFailureDisplaysExplicitSafeUserMessage(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_login')->willReturn('/login');
        $authenticator = $this->authenticator(router: $router);
        $request = new Request();
        $session = new Session(new MockArraySessionStorage(), null, new FlashBag());
        $request->setSession($session);

        $response = $authenticator->onAuthenticationFailure(
            $request,
            new CustomUserMessageAuthenticationException('Votre email doit être vérifié par Google.'),
        );

        self::assertSame('/login', $response->headers->get('Location'));
        self::assertSame(
            ['Votre email doit être vérifié par Google.'],
            $session->getFlashBag()->peek('error'),
        );
    }

    private function authenticator(
        ?UserRepository $userRepository = null,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?RouterInterface $router = null,
    ): GoogleAuthenticator {
        if (!$router instanceof RouterInterface) {
            $router = $this->createStub(RouterInterface::class);
            $router->method('generate')->willReturnMap([
                ['app_profile', [], 1, '/profile'],
                ['app_login', [], 1, '/login'],
            ]);
        }

        return new GoogleAuthenticator(
            $this->createStub(ClientRegistry::class),
            $userRepository ?? $this->createStub(UserRepository::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $passwordHasher ?? $this->createStub(UserPasswordHasherInterface::class),
            $router,
        );
    }

    private function invokeFindOrCreateUser(GoogleAuthenticator $authenticator, GoogleUser $googleUser): User
    {
        $method = new \ReflectionMethod($authenticator, 'findOrCreateUser');
        $user = $method->invoke($authenticator, $googleUser);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
