<?php

namespace App\Tests\Integration\Facebook;

use App\Entity\FacebookPublication;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Message\PublishFacebookContent;
use App\MessageHandler\PublishFacebookContentHandler;
use App\Repository\FacebookPublicationRepository;
use App\Service\Facebook\FacebookPublisher;
use App\Service\Facebook\FacebookPublisherException;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PublishFacebookContentHandlerTest extends IntegrationTestCase
{
    private const NOW = '2026-08-15T12:00:00+02:00';
    private const EXECUTION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_EXECUTION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const API_BASE_URL = 'https://graph.facebook.test/';
    private const TOKEN = 'test-facebook-handler-token';

    private static int $sourceId = 9_000_000;

    private FacebookPublicationRepository $publicationRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = $this->service(FacebookPublicationRepository::class);
        self::assertInstanceOf(FacebookPublicationRepository::class, $repository);
        $this->publicationRepository = $repository;
    }

    public function testItPublishesOutsideTheClaimTransactionAndCheckpointsSuccessOnlyOnce(): void
    {
        $publication = $this->persistPublication();
        $publicationId = $this->requiredId($publication);
        $connection = $this->entityManager->getConnection();
        $transactionLevel = $connection->getTransactionNestingLevel();
        $client = new MockHttpClient(
            static function () use ($connection, $transactionLevel): MockResponse {
                self::assertSame(
                    $transactionLevel,
                    $connection->getTransactionNestingLevel(),
                    'L’appel Facebook ne doit pas avoir lieu dans la transaction de claim.',
                );

                return new MockResponse('{"id":"1278125198721340_testpost"}');
            },
            self::API_BASE_URL,
        );
        $message = new PublishFacebookContent($publicationId, self::EXECUTION_ID);
        $handler = $this->handler($client);

        $handler($message);
        $handler($message);

        $stored = $this->reloadPublication($publicationId);
        self::assertSame(FacebookPublicationStatus::Published, $stored->getStatus());
        self::assertSame('1278125198721340_testpost', $stored->getFacebookPostId());
        self::assertNotNull($stored->getPublishedAt());
        self::assertNull($stored->getProcessingToken());
        self::assertSame(1, $stored->getAttemptCount());
        self::assertNull($stored->getLastError());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItRecordsASafeFailureThenLetsTheSameMessengerMessageRetry(): void
    {
        $publication = $this->persistPublication();
        $publicationId = $this->requiredId($publication);
        $message = new PublishFacebookContent($publicationId, self::EXECUTION_ID);
        $failureBody = json_encode([
            'error' => [
                'message' => 'Invalid token '.self::TOKEN,
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($failureBody, ['http_code' => 400]),
        ], self::API_BASE_URL);

        try {
            ($this->handler($client))($message);
            self::fail('Une FacebookPublisherException devait être propagée à Messenger.');
        } catch (FacebookPublisherException $exception) {
            self::assertSame(400, $exception->httpStatus);
            self::assertSame(190, $exception->metaErrorCode);
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        $failed = $this->reloadPublication($publicationId);
        self::assertSame(FacebookPublicationStatus::Failed, $failed->getStatus());
        self::assertSame(1, $failed->getAttemptCount());
        self::assertNull($failed->getProcessingToken());
        self::assertSame('http_400:meta_190', $failed->getLastErrorCode());
        self::assertStringNotContainsString(self::TOKEN, (string) $failed->getLastError());

        $retryClient = new MockHttpClient([
            new MockResponse('{"id":"1278125198721340_retry"}'),
        ], self::API_BASE_URL);
        ($this->handler($retryClient))($message);

        $published = $this->reloadPublication($publicationId);
        self::assertSame(FacebookPublicationStatus::Published, $published->getStatus());
        self::assertSame('1278125198721340_retry', $published->getFacebookPostId());
        self::assertSame(2, $published->getAttemptCount());
        self::assertSame(1, $retryClient->getRequestsCount());
    }

    public function testItResumesTheSameExecutionIdImmediately(): void
    {
        $publication = $this->persistPublication();
        $publication->markProcessing(self::EXECUTION_ID, new DateTimeImmutable(self::NOW));
        $this->entityManager->flush();
        $client = new MockHttpClient([
            new MockResponse('{"id":"1278125198721340_resumed"}'),
        ], self::API_BASE_URL);

        ($this->handler($client))(new PublishFacebookContent(
            $this->requiredId($publication),
            self::EXECUTION_ID,
        ));

        $stored = $this->reloadPublication($this->requiredId($publication));
        self::assertSame(FacebookPublicationStatus::Published, $stored->getStatus());
        self::assertSame(2, $stored->getAttemptCount());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testItIgnoresAnotherExecutionWhileItsLeaseIsFresh(): void
    {
        $publication = $this->persistPublication();
        $publication->markProcessing(self::OTHER_EXECUTION_ID, new DateTimeImmutable(self::NOW));
        $this->entityManager->flush();
        $publicationId = $this->requiredId($publication);
        $client = new MockHttpClient([], self::API_BASE_URL);

        ($this->handler($client))(new PublishFacebookContent($publicationId, self::EXECUTION_ID));

        $stored = $this->reloadPublication($publicationId);
        self::assertSame(FacebookPublicationStatus::Processing, $stored->getStatus());
        self::assertSame(self::OTHER_EXECUTION_ID, $stored->getProcessingToken());
        self::assertSame(1, $stored->getAttemptCount());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testItTakesOverAProcessingLeaseOlderThanThirtyMinutes(): void
    {
        $publication = $this->persistPublication();
        $publication->markProcessing(
            self::OTHER_EXECUTION_ID,
            new DateTimeImmutable('2026-08-15T11:29:59+02:00'),
        );
        $this->entityManager->flush();
        $publicationId = $this->requiredId($publication);
        $client = new MockHttpClient([
            new MockResponse('{"id":"1278125198721340_reclaimed"}'),
        ], self::API_BASE_URL);

        ($this->handler($client))(new PublishFacebookContent($publicationId, self::EXECUTION_ID));

        $stored = $this->reloadPublication($publicationId);
        self::assertSame(FacebookPublicationStatus::Published, $stored->getStatus());
        self::assertSame('1278125198721340_reclaimed', $stored->getFacebookPostId());
        self::assertSame(2, $stored->getAttemptCount());
        self::assertNull($stored->getProcessingToken());
        self::assertSame(1, $client->getRequestsCount());
    }

    private function persistPublication(): FacebookPublication
    {
        $publication = new FacebookPublication(
            FacebookPublicationSourceType::Hike,
            ++self::$sourceId,
            'Nouvelle randonnée sur Estela Exploration.',
            'https://estela-exploration.fr/randonnees/handler-test',
        );
        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        return $publication;
    }

    /** @param Closure(): DateTimeImmutable|null $now */
    private function handler(
        HttpClientInterface $httpClient,
        ?Closure $now = null,
    ): PublishFacebookContentHandler {
        return new PublishFacebookContentHandler(
            $this->entityManager,
            $this->publicationRepository,
            new FacebookPublisher(
                $httpClient,
                '1278125198721340',
                self::TOKEN,
                'v26.0',
            ),
            new NullLogger(),
            $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );
    }

    private function reloadPublication(int $publicationId): FacebookPublication
    {
        $this->entityManager->clear();
        $publication = $this->publicationRepository->find($publicationId);
        self::assertInstanceOf(FacebookPublication::class, $publication);

        return $publication;
    }

    private function requiredId(FacebookPublication $publication): int
    {
        $id = $publication->getId();
        self::assertNotNull($id);

        return $id;
    }
}
