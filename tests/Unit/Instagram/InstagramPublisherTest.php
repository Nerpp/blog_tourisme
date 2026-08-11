<?php

namespace App\Tests\Unit\Instagram;

use App\Service\Instagram\InstagramContainerStatus;
use App\Service\Instagram\InstagramPublisher;
use App\Service\Instagram\InstagramPublisherException;
use App\Service\Instagram\InstagramPublisherFailure;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class InstagramPublisherTest extends TestCase
{
    private const API_BASE_URL = 'https://graph.instagram.test';
    private const USER_ID = 'instagram-user-123';
    private const TOKEN = 'unit-test-token-never-send';

    public function testItCreatesAnImageContainerWithCaptionAndBearerAuthentication(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::API_BASE_URL.'/'.self::USER_ID.'/media', $url);
            $this->assertBearerIsOnlyInHeaders($url, $options);

            $body = $this->requestBody($options);
            self::assertSame([
                'image_url' => 'https://estela.example.test/images/hike.webp',
                'caption' => 'Une randonnée.',
            ], $body);

            return new MockResponse('{"id":"container-1"}');
        });

        $containerId = $this->publisher($client)->createImageContainer(
            'https://estela.example.test/images/hike.webp',
            'Une randonnée.',
            false,
        );

        self::assertSame('container-1', $containerId);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItCreatesACarouselChildWithoutCaption(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            $this->assertBearerIsOnlyInHeaders($url, $options);
            self::assertSame([
                'image_url' => 'https://estela.example.test/images/step.webp',
                'is_carousel_item' => 'true',
            ], $this->requestBody($options));

            return new MockResponse('{"id":"child-1"}');
        });

        self::assertSame(
            'child-1',
            $this->publisher($client)->createImageContainer(
                'https://estela.example.test/images/step.webp',
                null,
                true,
            ),
        );
    }

    public function testItCreatesACarouselParentWithChildrenInOrder(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::API_BASE_URL.'/'.self::USER_ID.'/media', $url);
            $this->assertBearerIsOnlyInHeaders($url, $options);
            self::assertSame([
                'media_type' => 'CAROUSEL',
                'children' => 'child-1,child-2,child-3',
                'caption' => 'Trois photos.',
            ], $this->requestBody($options));

            return new MockResponse('{"id":"carousel-1"}');
        });

        self::assertSame(
            'carousel-1',
            $this->publisher($client)->createCarouselContainer(
                ['child-1', 'child-2', 'child-3'],
                'Trois photos.',
            ),
        );
    }

    public function testItGetsTheContainerStatusWithTheOfficialFields(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            $this->assertBearerIsOnlyInHeaders($url, $options);

            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame(self::API_BASE_URL.'/container-1', sprintf(
                '%s://%s%s',
                $parts['scheme'] ?? '',
                $parts['host'] ?? '',
                $parts['path'] ?? '',
            ));

            parse_str((string) ($parts['query'] ?? ''), $query);
            self::assertSame(['fields' => 'status_code,status'], $query);

            return new MockResponse('{"status_code":"FINISHED","status":"Ready"}');
        });

        self::assertSame(
            InstagramContainerStatus::Finished,
            $this->publisher($client)->getContainerStatus('container-1'),
        );
    }

    public function testItWaitsOnlyBetweenChecksUntilTheContainerIsReady(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status_code":"IN_PROGRESS","status":"In progress"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
        ]);
        $delays = [];

        $status = $this->publisher(
            $client,
            pollAttempts: 4,
            pollDelayMilliseconds: 25,
            sleeper: static function (int $milliseconds) use (&$delays): void {
                $delays[] = $milliseconds;
            },
        )->waitUntilReady('container-1');

        self::assertSame(InstagramContainerStatus::Finished, $status);
        self::assertSame([25], $delays);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testItStopsPollingAfterTheConfiguredAttemptLimit(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"status_code":"IN_PROGRESS","status":"In progress"}'),
            new MockResponse('{"status_code":"IN_PROGRESS","status":"In progress"}'),
            new MockResponse('{"status_code":"IN_PROGRESS","status":"In progress"}'),
        ]);
        $delays = [];

        try {
            $this->publisher(
                $client,
                pollAttempts: 3,
                pollDelayMilliseconds: 10,
                sleeper: static function (int $milliseconds) use (&$delays): void {
                    $delays[] = $milliseconds;
                },
            )->waitUntilReady('container-1');
            self::fail('Une exception de timeout était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::PollingTimeout, $exception->failure);
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(3, $client->getRequestsCount());
        self::assertSame([10, 10], $delays);
    }

    public function testItStopsImmediatelyOnMetaErrorWithoutExposingTheStatusBody(): void
    {
        $responseBody = json_encode([
            'status_code' => 'ERROR',
            'status' => 'Private diagnostic '.self::TOKEN,
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([new MockResponse($responseBody)]);
        $sleeps = 0;

        try {
            $this->publisher(
                $client,
                pollAttempts: 3,
                sleeper: static function () use (&$sleeps): void {
                    ++$sleeps;
                },
            )->waitUntilReady('container-1');
            self::fail('Une exception de statut Meta était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::ContainerError, $exception->failure);
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('Private diagnostic', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(0, $sleeps);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItPublishesAReadyContainer(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::API_BASE_URL.'/'.self::USER_ID.'/media_publish', $url);
            $this->assertBearerIsOnlyInHeaders($url, $options);
            self::assertSame(['creation_id' => 'container-1'], $this->requestBody($options));

            return new MockResponse('{"id":"published-media-1"}');
        });

        self::assertSame(
            'published-media-1',
            $this->publisher($client)->publishContainer('container-1'),
        );
    }

    public function testItSanitizesClientAndServerHttpErrors(): void
    {
        foreach ([400, 503] as $httpStatus) {
            $body = json_encode([
                'error' => [
                    'message' => 'Sensitive response body '.self::TOKEN,
                    'code' => 100,
                    'error_subcode' => '2207001',
                ],
            ], JSON_THROW_ON_ERROR);
            $client = new MockHttpClient([new MockResponse($body, ['http_code' => $httpStatus])]);

            try {
                $this->publisher($client)->createImageContainer('https://estela.example.test/image.webp');
                self::fail(sprintf('Une exception HTTP %d était attendue.', $httpStatus));
            } catch (InstagramPublisherException $exception) {
                self::assertSame(InstagramPublisherFailure::Http, $exception->failure);
                self::assertSame($httpStatus, $exception->httpStatus);
                self::assertSame(100, $exception->metaErrorCode);
                self::assertSame(2_207_001, $exception->metaErrorSubcode);
                self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
                self::assertStringNotContainsString('Sensitive response body', $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function testItSanitizesMediaPublishFailures(): void
    {
        $client = new MockHttpClient([
            new MockResponse(
                '{"error":{"message":"Do not expose this","code":10}}',
                ['http_code' => 400],
            ),
        ]);

        try {
            $this->publisher($client)->publishContainer('container-1');
            self::fail('Une exception media_publish était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::Http, $exception->failure);
            self::assertSame(400, $exception->httpStatus);
            self::assertSame(10, $exception->metaErrorCode);
            self::assertStringNotContainsString('Do not expose this', $exception->getMessage());
        }
    }

    public function testItRejectsInvalidJsonWithoutExposingTheResponse(): void
    {
        $client = new MockHttpClient([new MockResponse('{invalid '.self::TOKEN)]);

        try {
            $this->publisher($client)->createImageContainer('https://estela.example.test/image.webp');
            self::fail('Une exception de réponse invalide était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::InvalidResponse, $exception->failure);
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('{invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItSanitizesTransportTimeoutsAndDropsTheOriginalException(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'error' => 'Timed out while sending '.self::TOKEN,
            ]),
        ]);

        try {
            $this->publisher($client)->createImageContainer('https://estela.example.test/image.webp');
            self::fail('Une exception de transport était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::Transport, $exception->failure);
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('Timed out', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItRejectsUnknownStatusAndMissingIdentifiers(): void
    {
        $statusClient = new MockHttpClient([
            new MockResponse('{"status_code":"UNKNOWN","status":"Unknown"}'),
        ]);

        try {
            $this->publisher($statusClient)->getContainerStatus('container-1');
            self::fail('Une exception de statut invalide était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::InvalidResponse, $exception->failure);
        }

        $idClient = new MockHttpClient([new MockResponse('{"unexpected":"value"}')]);

        try {
            $this->publisher($idClient)->createImageContainer('https://estela.example.test/image.webp');
            self::fail('Une exception d’identifiant absent était attendue.');
        } catch (InstagramPublisherException $exception) {
            self::assertSame(InstagramPublisherFailure::InvalidResponse, $exception->failure);
        }
    }

    public function testItRejectsUnsafeInputBeforeAnyRequest(): void
    {
        $client = new MockHttpClient([new MockResponse('{"id":"unused"}')]);
        $publisher = $this->publisher($client);

        foreach (
            [
                static fn (): string => $publisher->createImageContainer('http://estela.example.test/image.webp'),
                static fn (): string => $publisher->createImageContainer('https://user:password@estela.example.test/image.webp'),
                static fn (): string => $publisher->createCarouselContainer(['only-one-child'], 'Caption'),
                static fn (): string => $publisher->publishContainer('../unsafe'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Une exception de paramètre invalide était attendue.');
            } catch (InstagramPublisherException $exception) {
                self::assertSame(InstagramPublisherFailure::InvalidArgument, $exception->failure);
            }
        }

        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * @param array<mixed> $options
     */
    private function assertBearerIsOnlyInHeaders(string $url, array $options): void
    {
        self::assertStringNotContainsString(self::TOKEN, $url);
        self::assertStringNotContainsString('access_token', strtolower($url));

        $headers = $options['headers'] ?? null;
        if (!is_array($headers)) {
            self::fail('Les en-têtes HTTP normalisés sont absents.');
        }

        $headerLines = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                self::fail('Un en-tête HTTP normalisé est invalide.');
            }

            $headerLines[] = $header;
        }

        self::assertStringContainsString(
            'Authorization: Bearer '.self::TOKEN,
            implode("\n", $headerLines),
        );

        $body = $options['body'] ?? null;
        if (null !== $body) {
            if (!is_string($body)) {
                self::fail('Le corps HTTP normalisé est invalide.');
            }

            self::assertStringNotContainsString(self::TOKEN, $body);
        }
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, string>
     */
    private function requestBody(array $options): array
    {
        $encodedBody = $options['body'] ?? null;
        if (!is_string($encodedBody)) {
            self::fail('Le corps HTTP normalisé est absent.');
        }

        parse_str($encodedBody, $parsedBody);

        $body = [];
        foreach ($parsedBody as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                self::fail('Le corps HTTP normalisé ne contient pas des champs scalaires valides.');
            }

            $body[$key] = $value;
        }

        return $body;
    }

    private function publisher(
        HttpClientInterface $httpClient,
        int $pollAttempts = 3,
        int $pollDelayMilliseconds = 0,
        ?Closure $sleeper = null,
    ): InstagramPublisher {
        return new InstagramPublisher(
            httpClient: $httpClient,
            instagramUserId: self::USER_ID,
            instagramAccessToken: self::TOKEN,
            pollAttempts: $pollAttempts,
            pollDelayMilliseconds: $pollDelayMilliseconds,
            requestTimeoutSeconds: 2.0,
            sleeper: $sleeper,
            apiBaseUrl: self::API_BASE_URL,
        );
    }
}
