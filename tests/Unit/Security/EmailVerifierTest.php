<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\EmailVerifier;
use App\Service\Seo\PublicUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerifierTest extends TestCase
{
    public function testSendEmailConfirmationPersistsOneTimeSignatureAndBuildsEmail(): void
    {
        $user = (new User())
            ->setEmail('test@example.test')
            ->setDisplayName('Test User')
            ->setPassword('password');
        $this->setEntityId($user, 123);

        $helper = $this->createMock(VerifyEmailHelperInterface::class);
        $helper
            ->expects(self::once())
            ->method('generateSignature')
            ->with(
                'app_verify_email',
                '123',
                'test@example.test',
                self::callback(static function (array $parameters): bool {
                    self::assertSame(123, $parameters['id'] ?? null);
                    self::assertSame('initial-password', $parameters['purpose'] ?? null);
                    self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) ($parameters['nonce'] ?? ''));

                    return true;
                }),
            )
            ->willReturn(new VerifyEmailSignatureComponents(
                new \DateTimeImmutable('+30 minutes'),
                'https://example.test/verify?id=123&purpose=initial-password&signature=abc',
                time(),
            ));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('flush')
            ->willReturnCallback(static function () use ($user): void {
                self::assertSame(hash('sha256', 'abc'), $user->getEmailVerificationTokenHash());
            });

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame('Confirmez votre adresse email Estela Explorations', $email->getSubject());
                self::assertSame('registration/confirmation_email.html.twig', $email->getHtmlTemplate());
                self::assertSame('no-reply@example.test', $email->getFrom()[0]->getAddress());
                self::assertSame('test@example.test', $email->getTo()[0]->getAddress());
                self::assertSame(
                    'https://example.test/verify?id=123&purpose=initial-password&signature=abc',
                    $email->getContext()['signedUrl'],
                );

                return true;
            }));

        $this->verifier($helper, $mailer, $entityManager)->sendEmailConfirmation($user);
    }

    public function testSendEmailConfirmationRequiresPersistedUser(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('User must be persisted before sending a confirmation email.');

        $this->verifier(
            new FakeVerifyEmailHelper(),
            $this->createStub(MailerInterface::class),
            $this->createStub(EntityManagerInterface::class),
        )->sendEmailConfirmation((new User())->setEmail('test@example.test')->setDisplayName('Test')->setPassword('x'));
    }

    public function testValidSignatureAuthorizesPasswordFormWithoutVerifyingUser(): void
    {
        $user = (new User())
            ->setEmail('test@example.test')
            ->setDisplayName('Test User')
            ->setPassword('password')
            ->setEmailVerificationTokenHash(hash('sha256', 'abc'))
            ->setIsVerified(false);
        $this->setEntityId($user, 123);
        $request = Request::create('/verify?id=123&purpose=initial-password&signature=abc');
        $helper = new FakeVerifyEmailHelper();

        $this->verifier(
            $helper,
            $this->createStub(MailerInterface::class),
            $this->createStub(EntityManagerInterface::class),
        )->validateEmailConfirmation($request, $user);

        self::assertFalse($user->isVerified());
        self::assertSame([$request, '123', 'test@example.test'], $helper->validatedWith);
    }

    public function testValidSignedUrlIsRejectedWhenOneTimeSignatureDoesNotMatch(): void
    {
        $user = (new User())
            ->setEmail('test@example.test')
            ->setDisplayName('Test User')
            ->setPassword('password')
            ->setEmailVerificationTokenHash(hash('sha256', 'another-signature'));
        $this->setEntityId($user, 123);

        $this->expectException(InvalidSignatureException::class);

        $this->verifier(
            new FakeVerifyEmailHelper(),
            $this->createStub(MailerInterface::class),
            $this->createStub(EntityManagerInterface::class),
        )->validateEmailConfirmation(
            Request::create('/verify?id=123&purpose=initial-password&signature=abc'),
            $user,
        );
    }

    public function testInvalidCryptographicSignatureIsRejectedBeforeStateMutation(): void
    {
        $user = (new User())
            ->setEmail('test@example.test')
            ->setDisplayName('Test User')
            ->setPassword('password')
            ->setEmailVerificationTokenHash(hash('sha256', 'bad'))
            ->setIsVerified(false);
        $this->setEntityId($user, 123);
        $helper = new FakeVerifyEmailHelper(new InvalidSignatureException());

        $this->expectException(InvalidSignatureException::class);

        try {
            $this->verifier(
                $helper,
                $this->createStub(MailerInterface::class),
                $this->createStub(EntityManagerInterface::class),
            )->validateEmailConfirmation(
                Request::create('/verify?id=123&purpose=initial-password&signature=bad'),
                $user,
            );
        } finally {
            self::assertFalse($user->isVerified());
        }
    }

    private function verifier(
        VerifyEmailHelperInterface $helper,
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
    ): EmailVerifier {
        return new EmailVerifier(
            $helper,
            $mailer,
            $this->publicUrlGenerator(),
            $entityManager,
            'no-reply@example.test',
        );
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }

    private function publicUrlGenerator(): PublicUrlGenerator
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn(new RequestContext());

        return new PublicUrlGenerator($router, 'https://estela-exploration.fr');
    }
}

final class FakeVerifyEmailHelper implements VerifyEmailHelperInterface
{
    /** @var array{Request, string, string}|null */
    public ?array $validatedWith = null;

    public function __construct(private readonly ?VerifyEmailExceptionInterface $exception = null)
    {
    }

    /** @param array<string, mixed> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        return new VerifyEmailSignatureComponents(
            new \DateTimeImmutable('+30 minutes'),
            '/verify?purpose=initial-password&signature=abc',
            time(),
        );
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
    }

    public function validateEmailConfirmationFromRequest(Request $request, string $userId, string $userEmail): void
    {
        if ($this->exception instanceof VerifyEmailExceptionInterface) {
            throw $this->exception;
        }

        $this->validatedWith = [$request, $userId, $userEmail];
    }
}
