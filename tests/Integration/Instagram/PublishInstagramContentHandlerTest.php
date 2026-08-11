<?php

namespace App\Tests\Integration\Instagram;

use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Entity\MediaAsset;
use App\Enum\InstagramPublicationBatchStatus;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Message\PublishInstagramContent;
use App\MessageHandler\PublishInstagramContentHandler;
use App\Repository\InstagramPublicationBatchRepository;
use App\Repository\InstagramPublicationMediaRepository;
use App\Repository\InstagramPublicationRepository;
use App\Service\Instagram\InstagramExecutionBudget;
use App\Service\Instagram\InstagramPublisher;
use App\Service\Media\MediaDeletionService;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PublishInstagramContentHandlerTest extends IntegrationTestCase
{
    private const NOW = '2026-08-11T12:00:00+02:00';
    private const EXECUTION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const API_BASE_URL = 'https://graph.instagram.test';
    private const TOKEN = 'handler-test-token-never-send';

    private static int $sourceId = 8_000_000;

    private InstagramPublicationRepository $publicationRepository;
    private InstagramPublicationBatchRepository $batchRepository;
    private InstagramPublicationMediaRepository $mediaRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $publicationRepository = $this->service(InstagramPublicationRepository::class);
        $batchRepository = $this->service(InstagramPublicationBatchRepository::class);
        $mediaRepository = $this->service(InstagramPublicationMediaRepository::class);
        self::assertInstanceOf(InstagramPublicationRepository::class, $publicationRepository);
        self::assertInstanceOf(InstagramPublicationBatchRepository::class, $batchRepository);
        self::assertInstanceOf(InstagramPublicationMediaRepository::class, $mediaRepository);

        $this->publicationRepository = $publicationRepository;
        $this->batchRepository = $batchRepository;
        $this->mediaRepository = $mediaRepository;
    }

    public function testItPublishesOneImageAndNeverKeepsATransactionOpenDuringMetaCalls(): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $assetId = $this->onlyMedia($this->onlyBatch($publication))->getMediaAsset()?->getId();
        self::assertNotNull($assetId);
        $connection = $this->entityManager->getConnection();
        $transactionLevel = $connection->getTransactionNestingLevel();
        $responses = [
            new MockResponse('{"id":"single-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"single-post"}'),
        ];
        $responseIndex = 0;
        $client = new MockHttpClient(
            static function () use (
                $connection,
                $transactionLevel,
                &$responses,
                &$responseIndex,
            ): MockResponse {
                self::assertSame(
                    $transactionLevel,
                    $connection->getTransactionNestingLevel(),
                    'Un appel Meta ne doit pas avoir lieu dans une transaction du handler.',
                );

                return $responses[$responseIndex++];
            },
        );

        ($this->handler($client))(new PublishInstagramContent($publicationId, self::EXECUTION_ID));

        $stored = $this->reloadPublication($publicationId);
        $batch = $this->onlyBatch($stored);
        $media = $this->onlyMedia($batch);

        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertSame(1, $stored->getPublishedMediaCount());
        self::assertSame(1, $stored->getPublishedBatchCount());
        self::assertSame(1, $stored->getAttemptCount());
        self::assertNull($stored->getProcessingToken());
        self::assertNotNull($stored->getPublishedAt());
        self::assertSame(InstagramPublicationBatchStatus::Published, $batch->getStatus());
        self::assertSame('single-container', $batch->getContainerId());
        self::assertSame('single-post', $batch->getInstagramMediaId());
        self::assertSame('single-container', $media->getContainerId());
        self::assertNull($media->getMediaAsset(), 'Le pin MediaAsset doit être libéré après succès.');
        self::assertNull(
            $this->entityManager->find(MediaAsset::class, $assetId),
            'Un MediaAsset devenu orphelin doit être nettoyé après le checkpoint Published.',
        );
        self::assertSame(3, $client->getRequestsCount());
    }

    public function testItCreatesReadyChildrenThenTheCarouselParentInMediaOrder(): void
    {
        $publication = $this->persistPublication([2]);
        $publicationId = $this->requiredId($publication);
        $childOneCreation = new MockResponse('{"id":"child-1"}');
        $childTwoCreation = new MockResponse('{"id":"child-2"}');
        $parentCreation = new MockResponse('{"id":"parent-1"}');
        $publish = new MockResponse('{"id":"carousel-post"}');
        $client = new MockHttpClient([
            $childOneCreation,
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            $childTwoCreation,
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            $parentCreation,
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            $publish,
        ]);

        ($this->handler($client))(new PublishInstagramContent($publicationId, self::EXECUTION_ID));

        $stored = $this->reloadPublication($publicationId);
        $batch = $this->onlyBatch($stored);
        $media = $this->mediaRepository->findForBatchOrdered($batch);

        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertSame(2, $stored->getPublishedMediaCount());
        self::assertSame('parent-1', $batch->getContainerId());
        self::assertSame('carousel-post', $batch->getInstagramMediaId());
        self::assertSame(['child-1', 'child-2'], array_map(
            static fn (InstagramPublicationMedia $item): ?string => $item->getContainerId(),
            $media,
        ));
        self::assertNull($media[0]->getMediaAsset());
        self::assertNull($media[1]->getMediaAsset());

        self::assertSame('true', $this->requestBody($childOneCreation)['is_carousel_item'] ?? null);
        self::assertSame('true', $this->requestBody($childTwoCreation)['is_carousel_item'] ?? null);
        self::assertSame([
            'media_type' => 'CAROUSEL',
            'children' => 'child-1,child-2',
            'caption' => 'Publication 1/1',
        ], $this->requestBody($parentCreation));
        self::assertSame(['creation_id' => 'parent-1'], $this->requestBody($publish));
        self::assertSame(7, $client->getRequestsCount());
    }

    public function testItRefreshesTheProcessingHeartbeatWithoutIncreasingTheRootAttemptCount(): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $client = new MockHttpClient([
            new MockResponse('{"id":"heartbeat-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"heartbeat-post"}'),
        ]);
        $tick = 0;
        $clock = static function () use (&$tick): DateTimeImmutable {
            return (new DateTimeImmutable(self::NOW))->modify(sprintf('+%d minutes', $tick++));
        };

        ($this->handler($client, $clock))(new PublishInstagramContent(
            $publicationId,
            self::EXECUTION_ID,
        ));

        $stored = $this->reloadPublication($publicationId);
        self::assertSame(1, $stored->getAttemptCount());
        self::assertEquals(new DateTimeImmutable(self::NOW), $stored->getFirstAttemptAt());
        self::assertGreaterThan($stored->getFirstAttemptAt(), $stored->getLastAttemptAt());
    }

    public function testActiveHttpBudgetYieldsBetweenLotsAndNextMessageNeverReplaysPublishedLot(): void
    {
        $publication = $this->persistPublication([1, 1]);
        $publicationId = $this->requiredId($publication);
        $clock = new MockClock(self::NOW);
        $executionBudget = new InstagramExecutionBudget($clock, 120);
        $executionBudget->activate(150);
        $responses = [
            new MockResponse('{"id":"budget-batch-1-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"budget-batch-1-post"}'),
        ];
        $responseIndex = 0;
        $firstClient = new MockHttpClient(
            /** @param array<string, mixed> $options */
            static function (string $method, string $url, array $options) use (
                &$responseIndex,
                $responses,
                $clock,
            ): MockResponse {
                unset($method, $url, $options);
                $response = $responses[$responseIndex++];
                if (3 === $responseIndex) {
                    $clock->sleep(31);
                }

                return $response;
            },
        );

        ($this->handler($firstClient, executionBudget: $executionBudget))(
            new PublishInstagramContent($publicationId, self::EXECUTION_ID),
        );

        $yielded = $this->reloadPublication($publicationId);
        $yieldedBatches = $this->batchRepository->findForPublicationOrdered($yielded);
        self::assertSame(InstagramPublicationStatus::Pending, $yielded->getStatus());
        self::assertSame(1, $yielded->getPublishedBatchCount());
        self::assertNull($yielded->getProcessingToken());
        self::assertNull($yielded->getLastDispatchedAt());
        self::assertNull($yielded->getLastError());
        self::assertSame(InstagramPublicationBatchStatus::Published, $yieldedBatches[0]->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Pending, $yieldedBatches[1]->getStatus());
        self::assertSame(0, $yieldedBatches[1]->getAttemptCount());
        self::assertSame(3, $firstClient->getRequestsCount());

        $executionBudget->deactivate();
        $retryClient = new MockHttpClient([
            new MockResponse('{"id":"budget-batch-2-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"budget-batch-2-post"}'),
        ]);
        ($this->handler($retryClient))(
            new PublishInstagramContent($publicationId, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
        );

        $published = $this->reloadPublication($publicationId);
        $publishedBatches = $this->batchRepository->findForPublicationOrdered($published);
        self::assertSame(InstagramPublicationStatus::Published, $published->getStatus());
        self::assertSame(2, $published->getPublishedBatchCount());
        self::assertSame(1, $publishedBatches[0]->getAttemptCount());
        self::assertSame('budget-batch-1-post', $publishedBatches[0]->getInstagramMediaId());
        self::assertSame(1, $publishedBatches[1]->getAttemptCount());
        self::assertSame('budget-batch-2-post', $publishedBatches[1]->getInstagramMediaId());
        self::assertSame(3, $retryClient->getRequestsCount(), 'Le lot déjà publié ne doit pas être rejoué.');
    }

    public function testHttpBudgetYieldsBeforePollingAndKeepsTheCreatedContainerCheckpoint(): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $clock = new MockClock(self::NOW);
        $executionBudget = new InstagramExecutionBudget($clock, 120);
        $executionBudget->activate(150);
        $client = new MockHttpClient(
            /** @param array<string, mixed> $options */
            static function (string $method, string $url, array $options) use ($clock): MockResponse {
                unset($method, $url, $options);
                $clock->sleep(31);

                return new MockResponse('{"id":"poll-boundary-container"}');
            },
        );

        ($this->handler($client, executionBudget: $executionBudget))(
            new PublishInstagramContent($publicationId, self::EXECUTION_ID),
        );

        $stored = $this->reloadPublication($publicationId);
        $batch = $this->onlyBatch($stored);
        $media = $this->onlyMedia($batch);
        self::assertSame(InstagramPublicationStatus::Pending, $stored->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Pending, $batch->getStatus());
        self::assertNull($stored->getProcessingToken());
        self::assertNull($stored->getLastDispatchedAt());
        self::assertNull($stored->getLastError());
        self::assertNull($batch->getLastError());
        self::assertSame('poll-boundary-container', $batch->getContainerId());
        self::assertSame('poll-boundary-container', $media->getContainerId());
        self::assertNotNull($media->getMediaAsset());
        self::assertSame(1, $client->getRequestsCount(), 'Le polling ne doit pas commencer sans sa réserve.');
    }

    public function testHttpBudgetYieldsBeforeMediaPublishAndPreservesReadyContainer(): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $clock = new MockClock(self::NOW);
        $executionBudget = new InstagramExecutionBudget($clock, 120);
        $executionBudget->activate(150);
        $responseIndex = 0;
        $responses = [
            new MockResponse('{"id":"publish-boundary-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
        ];
        $client = new MockHttpClient(
            /** @param array<string, mixed> $options */
            static function (string $method, string $url, array $options) use (
                &$responseIndex,
                $responses,
                $clock,
            ): MockResponse {
                unset($method, $url, $options);
                $response = $responses[$responseIndex++];
                if (2 === $responseIndex) {
                    $clock->sleep(131);
                }

                return $response;
            },
        );

        ($this->handler($client, executionBudget: $executionBudget))(
            new PublishInstagramContent($publicationId, self::EXECUTION_ID),
        );

        $stored = $this->reloadPublication($publicationId);
        $batch = $this->onlyBatch($stored);
        self::assertSame(InstagramPublicationStatus::Pending, $stored->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Pending, $batch->getStatus());
        self::assertSame('publish-boundary-container', $batch->getContainerId());
        self::assertNull($batch->getInstagramMediaId());
        self::assertNull($stored->getLastError());
        self::assertNull($batch->getLastError());
        self::assertSame(2, $client->getRequestsCount(), 'media_publish doit attendre la prochaine enveloppe.');
    }

    public function testHttpBudgetYieldsMidCarouselWithEveryCreatedChildCheckpointIntact(): void
    {
        $publication = $this->persistPublication([2]);
        $publicationId = $this->requiredId($publication);
        $clock = new MockClock(self::NOW);
        $executionBudget = new InstagramExecutionBudget($clock, 120);
        $executionBudget->activate(240);
        $responseIndex = 0;
        $responses = [
            new MockResponse('{"id":"budget-child-1"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"budget-child-2"}'),
        ];
        $client = new MockHttpClient(
            /** @param array<string, mixed> $options */
            static function (string $method, string $url, array $options) use (
                &$responseIndex,
                $responses,
                $clock,
            ): MockResponse {
                unset($method, $url, $options);
                $response = $responses[$responseIndex++];
                if (2 === $responseIndex) {
                    $clock->sleep(121);
                }

                return $response;
            },
        );

        ($this->handler($client, executionBudget: $executionBudget))(
            new PublishInstagramContent($publicationId, self::EXECUTION_ID),
        );

        $stored = $this->reloadPublication($publicationId);
        $batch = $this->onlyBatch($stored);
        $media = $this->mediaRepository->findForBatchOrdered($batch);
        self::assertSame(InstagramPublicationStatus::Pending, $stored->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Pending, $batch->getStatus());
        self::assertNull($batch->getContainerId());
        self::assertSame(['budget-child-1', 'budget-child-2'], array_map(
            static fn (InstagramPublicationMedia $item): ?string => $item->getContainerId(),
            $media,
        ));
        self::assertNotNull($media[0]->getMediaAsset());
        self::assertNotNull($media[1]->getMediaAsset());
        self::assertNull($stored->getLastError());
        self::assertNull($batch->getLastError());
        self::assertSame(3, $client->getRequestsCount(), 'Le polling du second enfant est reporté.');
    }

    public function testInactiveHttpBudgetLeavesMultiBatchCliHandlingUnchanged(): void
    {
        $publication = $this->persistPublication([1, 1]);
        $client = new MockHttpClient([
            new MockResponse('{"id":"inactive-1-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"inactive-1-post"}'),
            new MockResponse('{"id":"inactive-2-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"inactive-2-post"}'),
        ]);

        ($this->handler($client))(
            new PublishInstagramContent($this->requiredId($publication), self::EXECUTION_ID),
        );

        $stored = $this->reloadPublication($this->requiredId($publication));
        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertSame(2, $stored->getPublishedBatchCount());
        self::assertSame(6, $client->getRequestsCount());
    }

    public function testPartialFailureThenRetrySkipsPublishedBatchAndReusesCheckpointedContainer(): void
    {
        $publication = $this->persistPublication([1, 1]);
        $publicationId = $this->requiredId($publication);
        $message = new PublishInstagramContent($publicationId, self::EXECUTION_ID);
        $firstClient = new MockHttpClient([
            new MockResponse('{"id":"batch-1-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"batch-1-post"}'),
            new MockResponse('{"id":"batch-2-container"}'),
            new MockResponse(
                '{"error":{"message":"Temporary upstream error","code":2}}',
                ['http_code' => 503],
            ),
        ]);

        try {
            ($this->handler($firstClient))($message);
            self::fail('Une exception Messenger récupérable était attendue.');
        } catch (RecoverableMessageHandlingException $exception) {
            self::assertFalse($exception->forceRetry());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('Temporary upstream error', $exception->getMessage());
        }

        $partial = $this->reloadPublication($publicationId);
        $partialBatches = $this->batchRepository->findForPublicationOrdered($partial);
        self::assertSame(InstagramPublicationStatus::PartialFailure, $partial->getStatus());
        self::assertSame(1, $partial->getPublishedBatchCount());
        self::assertSame(1, $partial->getPublishedMediaCount());
        self::assertNull($partial->getProcessingToken());
        self::assertSame(InstagramPublicationBatchStatus::Published, $partialBatches[0]->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Failed, $partialBatches[1]->getStatus());
        self::assertSame('batch-1-post', $partialBatches[0]->getInstagramMediaId());
        self::assertSame('batch-2-container', $partialBatches[1]->getContainerId());
        self::assertSame('http:http_503:meta_2', $partial->getLastErrorCode());

        $finishedStatus = new MockResponse('{"status_code":"FINISHED","status":"Ready"}');
        $secondPublish = new MockResponse('{"id":"batch-2-post"}');
        $retryClient = new MockHttpClient([$finishedStatus, $secondPublish]);

        ($this->handler($retryClient))($message);

        $published = $this->reloadPublication($publicationId);
        $publishedBatches = $this->batchRepository->findForPublicationOrdered($published);
        self::assertSame(InstagramPublicationStatus::Published, $published->getStatus());
        self::assertSame(2, $published->getPublishedBatchCount());
        self::assertSame(2, $published->getPublishedMediaCount());
        self::assertSame(2, $published->getAttemptCount());
        self::assertSame(1, $publishedBatches[0]->getAttemptCount());
        self::assertSame(2, $publishedBatches[1]->getAttemptCount());
        self::assertSame('batch-1-post', $publishedBatches[0]->getInstagramMediaId());
        self::assertSame('batch-2-post', $publishedBatches[1]->getInstagramMediaId());
        self::assertSame('GET', $finishedStatus->getRequestMethod());
        self::assertStringEndsWith('/batch-2-container?fields=status_code%2Cstatus', $finishedStatus->getRequestUrl());
        self::assertSame(['creation_id' => 'batch-2-container'], $this->requestBody($secondPublish));
        self::assertSame(2, $retryClient->getRequestsCount(), 'Le lot 1 ne doit jamais être republié.');
    }

    public function testItRecoversAPublishedContainerWithoutCallingMediaPublishAgain(): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $batch = $this->onlyBatch($publication);
        $media = $this->onlyMedia($batch);
        $batch->setContainerId('already-published-container');
        $media->setContainerId('already-published-container');
        $this->entityManager->flush();

        $status = new MockResponse('{"status_code":"PUBLISHED","status":"Published"}');
        $client = new MockHttpClient([$status]);

        ($this->handler($client))(new PublishInstagramContent($publicationId, self::EXECUTION_ID));

        $stored = $this->reloadPublication($publicationId);
        $storedBatch = $this->onlyBatch($stored);
        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Published, $storedBatch->getStatus());
        self::assertNull(
            $storedBatch->getInstagramMediaId(),
            'L’API ne permet pas toujours de retrouver l’ID final après perte de la réponse media_publish.',
        );
        self::assertSame('GET', $status->getRequestMethod());
        self::assertSame(1, $client->getRequestsCount());
        self::assertNull($this->onlyMedia($storedBatch)->getMediaAsset());
    }

    public function testLostMediaPublishResponseKeepsCheckpointAndRetryOnlyChecksPublishedStatus(): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $message = new PublishInstagramContent($publicationId, self::EXECUTION_ID);
        $firstClient = new MockHttpClient([
            new MockResponse('{"id":"lost-response-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('', [
                'error' => 'Connection lost after media_publish '.self::TOKEN,
            ]),
        ]);

        try {
            ($this->handler($firstClient))($message);
            self::fail('Une exception Messenger récupérable était attendue.');
        } catch (RecoverableMessageHandlingException $exception) {
            self::assertFalse($exception->forceRetry());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }

        $failed = $this->reloadPublication($publicationId);
        $failedBatch = $this->onlyBatch($failed);
        self::assertSame(InstagramPublicationStatus::Failed, $failed->getStatus());
        self::assertSame('transport', $failed->getLastErrorCode());
        self::assertSame('lost-response-container', $failedBatch->getContainerId());
        self::assertSame('lost-response-container', $this->onlyMedia($failedBatch)->getContainerId());

        $publishedStatus = new MockResponse('{"status_code":"PUBLISHED","status":"Published"}');
        $retryClient = new MockHttpClient([$publishedStatus]);
        ($this->handler($retryClient))($message);

        $published = $this->reloadPublication($publicationId);
        $publishedBatch = $this->onlyBatch($published);
        self::assertSame(InstagramPublicationStatus::Published, $published->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Published, $publishedBatch->getStatus());
        self::assertNull($publishedBatch->getInstagramMediaId());
        self::assertSame('GET', $publishedStatus->getRequestMethod());
        self::assertSame(1, $retryClient->getRequestsCount());
    }

    public function testItSkipsFreshConcurrentClaimsAndTerminalPublications(): void
    {
        $active = $this->persistPublication([1]);
        $active
            ->setStatus(InstagramPublicationStatus::Processing)
            ->setProcessingToken('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
            ->setLastAttemptAt(new DateTimeImmutable(self::NOW));
        $terminal = $this->persistPublication([1]);
        $terminal->setStatus(InstagramPublicationStatus::Published);
        $this->entityManager->flush();
        $activeId = $this->requiredId($active);
        $terminalId = $this->requiredId($terminal);

        $storedBeforeHandling = $this->reloadPublication($activeId);
        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $storedBeforeHandling->getProcessingToken());
        self::assertEquals(new DateTimeImmutable(self::NOW), $storedBeforeHandling->getLastAttemptAt());

        $client = new MockHttpClient([]);
        $handler = $this->handler($client);
        $handler(new PublishInstagramContent($activeId, self::EXECUTION_ID));
        $handler(new PublishInstagramContent(
            $terminalId,
            'cccccccccccccccccccccccccccccccc',
        ));

        $storedActive = $this->reloadPublication($activeId);
        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $storedActive->getProcessingToken());
        self::assertSame(InstagramPublicationStatus::Processing, $storedActive->getStatus());
        self::assertSame(0, $storedActive->getAttemptCount());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testItTakesOverAProcessingLeaseOlderThanThirtyMinutes(): void
    {
        $publication = $this->persistPublication([1]);
        $publication
            ->setStatus(InstagramPublicationStatus::Processing)
            ->setProcessingToken('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
            ->setAttemptCount(1)
            ->setLastAttemptAt(new DateTimeImmutable('2026-08-11T11:29:59+02:00'));
        $batch = $this->onlyBatch($publication);
        $batch
            ->setStatus(InstagramPublicationBatchStatus::Processing)
            ->setAttemptCount(1);
        $this->entityManager->flush();

        $client = new MockHttpClient([
            new MockResponse('{"id":"takeover-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"takeover-post"}'),
        ]);

        ($this->handler($client))(new PublishInstagramContent(
            $this->requiredId($publication),
            self::EXECUTION_ID,
        ));

        $stored = $this->reloadPublication($this->requiredId($publication));
        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertSame(2, $stored->getAttemptCount());
        self::assertNull($stored->getProcessingToken());
        self::assertSame(2, $this->onlyBatch($stored)->getAttemptCount());
    }

    public function testItImmediatelyResumesTheSameExecutionIdAfterMessengerRedelivery(): void
    {
        $publication = $this->persistPublication([1]);
        $publication
            ->setStatus(InstagramPublicationStatus::Processing)
            ->setProcessingToken(self::EXECUTION_ID)
            ->setAttemptCount(1)
            ->setLastAttemptAt(new DateTimeImmutable(self::NOW));
        $batch = $this->onlyBatch($publication);
        $media = $this->onlyMedia($batch);
        $batch
            ->setStatus(InstagramPublicationBatchStatus::Processing)
            ->setAttemptCount(1)
            ->setContainerId('checkpointed-container');
        $media->setContainerId('checkpointed-container');
        $this->entityManager->flush();

        $status = new MockResponse('{"status_code":"FINISHED","status":"Ready"}');
        $publish = new MockResponse('{"id":"resumed-post"}');
        $client = new MockHttpClient([$status, $publish]);

        ($this->handler($client))(new PublishInstagramContent(
            $this->requiredId($publication),
            self::EXECUTION_ID,
        ));

        $stored = $this->reloadPublication($this->requiredId($publication));
        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertSame(2, $stored->getAttemptCount());
        self::assertSame(2, $this->onlyBatch($stored)->getAttemptCount());
        self::assertSame('GET', $status->getRequestMethod());
        self::assertSame(['creation_id' => 'checkpointed-container'], $this->requestBody($publish));
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testItTakesOverAnInconsistentTokenWithoutAttemptTimestamp(): void
    {
        $publication = $this->persistPublication([1]);
        $publication
            ->setStatus(InstagramPublicationStatus::Processing)
            ->setProcessingToken('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
            ->setLastAttemptAt(null);
        $this->onlyBatch($publication)->setStatus(InstagramPublicationBatchStatus::Processing);
        $this->entityManager->flush();

        $client = new MockHttpClient([
            new MockResponse('{"id":"recovered-container"}'),
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"recovered-post"}'),
        ]);

        ($this->handler($client))(new PublishInstagramContent(
            $this->requiredId($publication),
            self::EXECUTION_ID,
        ));

        $stored = $this->reloadPublication($this->requiredId($publication));
        self::assertSame(InstagramPublicationStatus::Published, $stored->getStatus());
        self::assertNull($stored->getProcessingToken());
        self::assertSame(1, $stored->getAttemptCount());
        self::assertSame(3, $client->getRequestsCount());
    }

    /** @param class-string<\Throwable> $expectedMessengerException */
    #[DataProvider('terminalContainerFailureProvider')]
    public function testTerminalContainerFailureClearsIdsSoRetryRecreatesAndSucceeds(
        string $statusCode,
        string $expectedFailureCode,
        string $expectedMessengerException,
    ): void
    {
        $publication = $this->persistPublication([1]);
        $publicationId = $this->requiredId($publication);
        $client = new MockHttpClient([
            new MockResponse('{"id":"error-container"}'),
            new MockResponse(sprintf(
                '{"status_code":"%s","status":"Private details %s"}',
                $statusCode,
                self::TOKEN,
            )),
        ]);

        try {
            ($this->handler($client))(new PublishInstagramContent($publicationId, self::EXECUTION_ID));
            self::fail('Une exception Messenger était attendue.');
        } catch (RecoverableMessageHandlingException|UnrecoverableMessageHandlingException $exception) {
            self::assertInstanceOf($expectedMessengerException, $exception);
            if ($exception instanceof RecoverableMessageHandlingException) {
                self::assertFalse($exception->forceRetry());
            }
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('Private details', $exception->getMessage());
        }

        $stored = $this->reloadPublication($publicationId);
        $batch = $this->onlyBatch($stored);
        self::assertSame(InstagramPublicationStatus::Failed, $stored->getStatus());
        self::assertSame(InstagramPublicationBatchStatus::Failed, $batch->getStatus());
        self::assertSame($expectedFailureCode, $stored->getLastErrorCode());
        self::assertSame($expectedFailureCode, $batch->getLastErrorCode());
        self::assertStringNotContainsString(self::TOKEN, (string) $stored->getLastError());
        self::assertNull($stored->getProcessingToken());
        self::assertNull($batch->getContainerId());
        $failedMedia = $this->onlyMedia($batch);
        self::assertNull($failedMedia->getContainerId());
        self::assertNull($failedMedia->getContainerCreatedAt());
        self::assertNotNull($failedMedia->getMediaAsset(), 'Un lot échoué doit rester épinglé pour son retry.');

        $newCreation = new MockResponse('{"id":"fresh-container"}');
        $retryClient = new MockHttpClient([
            $newCreation,
            new MockResponse('{"status_code":"FINISHED","status":"Ready"}'),
            new MockResponse('{"id":"fresh-post"}'),
        ]);

        ($this->handler($retryClient))(new PublishInstagramContent($publicationId, self::EXECUTION_ID));

        $published = $this->reloadPublication($publicationId);
        $publishedBatch = $this->onlyBatch($published);
        self::assertSame(InstagramPublicationStatus::Published, $published->getStatus());
        self::assertSame('fresh-container', $publishedBatch->getContainerId());
        self::assertSame('fresh-post', $publishedBatch->getInstagramMediaId());
        self::assertSame('POST', $newCreation->getRequestMethod());
        self::assertSame(3, $retryClient->getRequestsCount());
    }

    /**
     * @return iterable<string, array{string, string, class-string<\Throwable>}>
     */
    public static function terminalContainerFailureProvider(): iterable
    {
        yield 'ERROR is permanent but manually retryable' => [
            'ERROR',
            'container_error',
            UnrecoverableMessageHandlingException::class,
        ];
        yield 'EXPIRED is automatically retryable' => [
            'EXPIRED',
            'container_expired',
            RecoverableMessageHandlingException::class,
        ];
    }

    /**
     * @param non-empty-list<int> $batchSizes
     */
    private function persistPublication(array $batchSizes): InstagramPublication
    {
        $publication = new InstagramPublication(
            InstagramPublicationSourceType::Hike,
            ++self::$sourceId,
            'Légende principale',
        );
        $globalPosition = 1;

        foreach ($batchSizes as $batchIndex => $mediaCount) {
            $batch = new InstagramPublicationBatch(
                $publication,
                $batchIndex + 1,
                sprintf('Publication %d/%d', $batchIndex + 1, count($batchSizes)),
            );

            for ($batchPosition = 1; $batchPosition <= $mediaCount; ++$batchPosition) {
                $asset = (new MediaAsset())
                    ->setFilePath(sprintf('/uploads/media/instagram-%d-%d.webp', $batchIndex, $batchPosition))
                    ->setMimeType('image/webp');
                $this->entityManager->persist($asset);

                new InstagramPublicationMedia(
                    $publication,
                    $batch,
                    $asset,
                    null,
                    $globalPosition,
                    $batchPosition,
                    sprintf('https://estela.example.test/images/%d.webp', $globalPosition++),
                );
            }

            $batch->synchronizeMediaCount();
        }

        $publication->synchronizeCounters();
        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        return $publication;
    }

    /** @param Closure(): DateTimeImmutable|null $now */
    private function handler(
        HttpClientInterface $httpClient,
        ?Closure $now = null,
        ?InstagramExecutionBudget $executionBudget = null,
    ): PublishInstagramContentHandler
    {
        $publisher = new InstagramPublisher(
            httpClient: $httpClient,
            instagramUserId: 'instagram-user-id',
            instagramAccessToken: self::TOKEN,
            pollAttempts: 1,
            pollDelayMilliseconds: 0,
            requestTimeoutSeconds: 1.0,
            sleeper: static function (): void {
            },
            apiBaseUrl: self::API_BASE_URL,
        );

        return new PublishInstagramContentHandler(
            $this->entityManager,
            $this->publicationRepository,
            $this->batchRepository,
            $this->mediaRepository,
            $publisher,
            $this->mediaDeletionService(),
            $executionBudget ?? new InstagramExecutionBudget(new MockClock(self::NOW), 120),
            new NullLogger(),
            $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
        );
    }

    private function mediaDeletionService(): MediaDeletionService
    {
        $service = $this->service(MediaDeletionService::class);
        self::assertInstanceOf(MediaDeletionService::class, $service);

        return $service;
    }

    private function reloadPublication(int $publicationId): InstagramPublication
    {
        $this->entityManager->clear();
        $publication = $this->publicationRepository->find($publicationId);
        self::assertInstanceOf(InstagramPublication::class, $publication);

        return $publication;
    }

    private function requiredId(InstagramPublication $publication): int
    {
        $id = $publication->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function onlyBatch(InstagramPublication $publication): InstagramPublicationBatch
    {
        $batches = $this->batchRepository->findForPublicationOrdered($publication);
        self::assertCount(1, $batches);

        return $batches[0];
    }

    private function onlyMedia(InstagramPublicationBatch $batch): InstagramPublicationMedia
    {
        $media = $this->mediaRepository->findForBatchOrdered($batch);
        self::assertCount(1, $media);

        return $media[0];
    }

    /** @return array<string, string> */
    private function requestBody(MockResponse $response): array
    {
        $options = $response->getRequestOptions();
        $encodedBody = $options['body'] ?? null;
        self::assertIsString($encodedBody);
        parse_str($encodedBody, $parsedBody);

        $body = [];
        foreach ($parsedBody as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            $body[$key] = $value;
        }

        return $body;
    }
}
