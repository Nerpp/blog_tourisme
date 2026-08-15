<?php

namespace App\Tests\Unit\Facebook;

use App\Entity\FacebookPublication;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FacebookPublicationTest extends TestCase
{
    private const EXECUTION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testItTracksACompleteSuccessfulLifecycle(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-15T09:00:00+02:00');
        $attemptedAt = new DateTimeImmutable('2026-08-15T09:05:00+02:00');
        $publishedAt = new DateTimeImmutable('2026-08-15T09:06:00+02:00');
        $publication = new FacebookPublication(
            FacebookPublicationSourceType::Hike,
            42,
            'Nouvelle randonnée.',
            'https://estela-exploration.fr/randonnees/test',
            $createdAt,
        );

        self::assertSame(FacebookPublicationStatus::Pending, $publication->getStatus());
        self::assertSame(0, $publication->getAttemptCount());
        self::assertSame($createdAt, $publication->getCreatedAt());
        self::assertFalse($publication->isTerminal());

        $publication->markProcessing(self::EXECUTION_ID, $attemptedAt);

        self::assertSame(FacebookPublicationStatus::Processing, $publication->getStatus());
        self::assertSame(1, $publication->getAttemptCount());
        self::assertSame($attemptedAt, $publication->getFirstAttemptAt());
        self::assertSame($attemptedAt, $publication->getLastAttemptAt());
        self::assertSame(self::EXECUTION_ID, $publication->getProcessingToken());

        $publication->markPublished('1278125198721340_testpost', $publishedAt);

        self::assertSame(FacebookPublicationStatus::Published, $publication->getStatus());
        self::assertSame('1278125198721340_testpost', $publication->getFacebookPostId());
        self::assertSame($publishedAt, $publication->getPublishedAt());
        self::assertSame($publishedAt, $publication->getLastAttemptAt());
        self::assertNull($publication->getProcessingToken());
        self::assertNull($publication->getLastError());
        self::assertNull($publication->getLastErrorCode());
        self::assertTrue($publication->isTerminal());
    }

    public function testItRecordsASanitizedFailureAndReleasesTheProcessingToken(): void
    {
        $failedAt = new DateTimeImmutable('2026-08-15T10:00:00+02:00');
        $publication = $this->publication();
        $publication->markProcessing(self::EXECUTION_ID, $failedAt->modify('-1 minute'));
        $publication->markFailed(
            'Bearer secret-value access_token=another-secret',
            'http_400:meta_190',
            $failedAt,
        );

        self::assertSame(FacebookPublicationStatus::Failed, $publication->getStatus());
        self::assertSame(1, $publication->getAttemptCount());
        self::assertSame($failedAt, $publication->getLastAttemptAt());
        self::assertNull($publication->getProcessingToken());
        self::assertSame('http_400:meta_190', $publication->getLastErrorCode());
        self::assertStringNotContainsString('secret-value', (string) $publication->getLastError());
        self::assertStringNotContainsString('another-secret', (string) $publication->getLastError());
        self::assertStringContainsString('[redacted]', (string) $publication->getLastError());
        self::assertFalse($publication->isTerminal());
    }

    private function publication(): FacebookPublication
    {
        return new FacebookPublication(
            FacebookPublicationSourceType::CityVisit,
            84,
            'Nouvelle visite.',
            'https://estela-exploration.fr/visites-de-ville/test',
        );
    }
}
