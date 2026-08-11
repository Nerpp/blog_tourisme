<?php

namespace App\Tests\Functional;

use App\Controller\Internal\InstagramWebCronController;
use App\Service\Instagram\InstagramWebCronProcessorInterface;
use App\Tests\Support\RecordingInstagramWebCronProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

final class InstagramWebCronControllerTest extends FunctionalTestCase
{
    private const string ENDPOINT = 'https://localhost/_internal/cron/instagram';
    private const string SECRET = 'test-instagram-cron-secret-with-at-least-32-characters';

    public function testAuthenticatedGetRunsTheProcessorAndReturnsTheStableMarker(): void
    {
        [$client, $processor] = $this->authenticatedRequest('GET');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $processor->callCount);
        self::assertSame(
            ['status' => 'ok', 'accepted' => true],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertProtectedResponseHeaders($client->getResponse());
    }

    public function testAuthenticatedPostIsAcceptedToo(): void
    {
        [$client, $processor] = $this->authenticatedRequest('POST');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $processor->callCount);
        self::assertStringContainsString('"accepted":true', (string) $client->getResponse()->getContent());
    }

    public function testAuthenticatedHeadIsASideEffectFreeProbeAndIsNotProfiled(): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $processor = new RecordingInstagramWebCronProcessor();
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLock');
        static::getContainer()->set(InstagramWebCronController::class, new InstagramWebCronController(
            $processor,
            $lockFactory,
            new NullLogger(),
            self::SECRET,
        ));

        $client->request('HEAD', self::ENDPOINT, server: $this->authorizationServer());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(0, $processor->callCount);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertNull($client->getProfile());
        $this->assertProtectedResponseHeaders($client->getResponse());
    }

    /** @param array<string, string> $server */
    #[DataProvider('refusedAuthenticationProvider')]
    public function testMissingOrInvalidCredentialsAreRefusedWithoutLeakingSecrets(array $server): void
    {
        $client = static::createClient();
        $processor = $this->replaceProcessor();

        $client->request('GET', self::ENDPOINT, server: $server);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(0, $processor->callCount);
        self::assertSame('Basic realm="Estela WebCron", charset="UTF-8"', $client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('"accepted":true', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function refusedAuthenticationProvider(): iterable
    {
        yield 'missing credentials' => [[]];
        yield 'wrong username' => [[
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('wrong-user:'.self::SECRET),
        ]];
        yield 'wrong password' => [[
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode(InstagramWebCronController::BASIC_USERNAME.':wrong-secret'),
        ]];
    }

    public function testPlainHttpIsRefusedBeforeAuthenticationOrProcessing(): void
    {
        $client = static::createClient();
        $processor = $this->replaceProcessor();

        $client->request('GET', 'http://localhost/_internal/cron/instagram', server: $this->authorizationServer());

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(0, $processor->callCount);
        self::assertStringNotContainsString('"accepted":true', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('unsupportedMethodProvider')]
    public function testUnsupportedMethodIsRejectedByRouting(string $method): void
    {
        $client = static::createClient();
        $client->enableProfiler();
        $processor = $this->replaceProcessor();

        $client->request($method, self::ENDPOINT, server: $this->authorizationServer());

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertSame(0, $processor->callCount);
        self::assertSame('GET, HEAD, POST', $client->getResponse()->headers->get('Allow'));
        self::assertNull($client->getProfile());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedMethodProvider(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('invalidSecretConfigurationProvider')]
    public function testMissingOrInvalidConfiguredSecretFailsClosed(?string $configuredSecret): void
    {
        $client = static::createClient();
        $processor = new RecordingInstagramWebCronProcessor();
        $lockFactory = static::getContainer()->get('lock.instagram_webcron.factory');
        self::assertInstanceOf(LockFactory::class, $lockFactory);
        static::getContainer()->set(InstagramWebCronController::class, new InstagramWebCronController(
            $processor,
            $lockFactory,
            new NullLogger(),
            $configuredSecret,
        ));

        $client->request('GET', self::ENDPOINT, server: $this->authorizationServer());

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame(0, $processor->callCount);
        self::assertSame(
            ['status' => 'unavailable'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('"accepted":true', (string) $client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidSecretConfigurationProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'too short' => ['short'];
        yield 'contains whitespace' => [str_repeat('a', 31).' '];
        yield 'contains control byte' => [str_repeat('a', 32)."\n"];
    }

    public function testConcurrentInvocationReturnsBusyWithoutRunningTheProcessor(): void
    {
        $client = static::createClient();
        $processor = $this->replaceProcessor();
        $lockFactory = static::getContainer()->get('lock.instagram_webcron.factory');
        self::assertInstanceOf(LockFactory::class, $lockFactory);
        $lock = $lockFactory->createLock(InstagramWebCronController::LOCK_RESOURCE, 720, true);
        self::assertTrue($lock->acquire());

        try {
            $client->request('GET', self::ENDPOINT, server: $this->authorizationServer());
        } finally {
            $lock->release();
        }

        self::assertResponseIsSuccessful();
        self::assertSame(0, $processor->callCount);
        self::assertSame(
            ['status' => 'busy', 'accepted' => true],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testInternalFailureReturnsMinimalUnavailableResponse(): void
    {
        $client = static::createClient();
        $processor = $this->replaceProcessor();
        $processor->exception = new \RuntimeException('sensitive internal detail '.self::SECRET);

        $client->request('GET', self::ENDPOINT, server: $this->authorizationServer());

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame(1, $processor->callCount);
        self::assertSame(
            ['status' => 'unavailable'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('"accepted":true', (string) $client->getResponse()->getContent());
    }

    /**
     * @return array{KernelBrowser, RecordingInstagramWebCronProcessor}
     */
    private function authenticatedRequest(string $method): array
    {
        $client = static::createClient();
        $processor = $this->replaceProcessor();
        $client->request($method, self::ENDPOINT, server: $this->authorizationServer());

        return [$client, $processor];
    }

    private function replaceProcessor(): RecordingInstagramWebCronProcessor
    {
        $processor = new RecordingInstagramWebCronProcessor();
        static::getContainer()->set(InstagramWebCronProcessorInterface::class, $processor);

        return $processor;
    }

    /**
     * @return array<string, string>
     */
    private function authorizationServer(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode(InstagramWebCronController::BASIC_USERNAME.':'.self::SECRET),
        ];
    }

    private function assertProtectedResponseHeaders(Response $response): void
    {
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
    }
}
