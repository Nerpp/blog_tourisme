<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\EmailVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerifierTest extends TestCase
{
    public function testSendEmailConfirmationBuildsTemplatedEmailWithoutExternalTransport(): void
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
            ->with('app_verify_email', '123', 'test@example.test', ['id' => 123])
            ->willReturn(new VerifyEmailSignatureComponents(
                new \DateTimeImmutable('+30 minutes'),
                'https://example.test/verify?id=123&signature=abc',
                time(),
            ));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame('Confirmez votre adresse email Estela Explorations', $email->getSubject());
                self::assertSame('registration/confirmation_email.html.twig', $email->getHtmlTemplate());
                self::assertSame('no-reply@example.test', $email->getFrom()[0]->getAddress());
                self::assertSame('test@example.test', $email->getTo()[0]->getAddress());
                self::assertSame('https://example.test/verify?id=123&signature=abc', $email->getContext()['signedUrl']);

                return true;
            }));

        (new EmailVerifier($helper, $mailer, 'no-reply@example.test'))->sendEmailConfirmation($user);
    }

    public function testSendEmailConfirmationRequiresPersistedUser(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('User must be persisted before sending a confirmation email.');

        (new EmailVerifier(
            new FakeVerifyEmailHelper(),
            $this->createStub(MailerInterface::class),
            'no-reply@example.test',
        ))->sendEmailConfirmation((new User())->setEmail('test@example.test')->setDisplayName('Test')->setPassword('x'));
    }

    public function testHandleEmailConfirmationMarksUserVerifiedAfterValidSignature(): void
    {
        $user = (new User())
            ->setEmail('test@example.test')
            ->setDisplayName('Test User')
            ->setPassword('password')
            ->setIsVerified(false);
        $this->setEntityId($user, 123);
        $request = Request::create('/verify?id=123&signature=abc');

        $helper = new FakeVerifyEmailHelper();

        (new EmailVerifier($helper, $this->createStub(MailerInterface::class), 'no-reply@example.test'))
            ->handleEmailConfirmation($request, $user);

        self::assertTrue($user->isVerified());
        self::assertSame([$request, '123', 'test@example.test'], $helper->validatedWith);
    }

    public function testHandleEmailConfirmationKeepsUserUnverifiedWhenSignatureIsInvalid(): void
    {
        $user = (new User())
            ->setEmail('test@example.test')
            ->setDisplayName('Test User')
            ->setPassword('password')
            ->setIsVerified(false);
        $this->setEntityId($user, 123);

        $helper = new FakeVerifyEmailHelper(new InvalidSignatureException());

        $this->expectException(InvalidSignatureException::class);

        try {
            (new EmailVerifier($helper, $this->createStub(MailerInterface::class), 'no-reply@example.test'))
                ->handleEmailConfirmation(Request::create('/verify?id=123&signature=bad'), $user);
        } finally {
            self::assertFalse($user->isVerified());
        }
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
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
        return new VerifyEmailSignatureComponents(new \DateTimeImmutable('+30 minutes'), '/verify', time());
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
