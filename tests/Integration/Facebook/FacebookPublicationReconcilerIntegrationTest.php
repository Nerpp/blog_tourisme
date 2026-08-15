<?php

namespace App\Tests\Integration\Facebook;

use App\Entity\FacebookPublication;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Message\PublishFacebookContent;
use App\Service\Facebook\FacebookPublicationReconciler;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FacebookPublicationReconcilerIntegrationTest extends IntegrationTestCase
{
    public function testItRecoversOnlyOldPendingDispatchesAndNeverFailedOnes(): void
    {
        $transport = $this->service('messenger.transport.facebook_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $pending = $this->publication(new \DateTimeImmutable('2000-01-01T00:00:00+00:00'));
        $failed = $this->publication(new \DateTimeImmutable('2000-01-01T00:00:00+00:00'))
            ->markFailed('Échec terminal de test.', 'test_failed');
        $this->entityManager->persist($pending);
        $this->entityManager->persist($failed);
        $this->entityManager->flush();
        $pendingId = $pending->getId();
        self::assertNotNull($pendingId);

        $reconciler = $this->service(FacebookPublicationReconciler::class);
        self::assertInstanceOf(FacebookPublicationReconciler::class, $reconciler);
        $now = new \DateTimeImmutable('2001-01-01T00:00:00+00:00');

        $first = $reconciler->reconcile($now);
        $second = $reconciler->reconcile($now);

        self::assertSame(1, $first->candidateCount);
        self::assertSame(1, $first->dispatchedCount);
        self::assertSame(0, $second->candidateCount);
        self::assertSame(0, $second->dispatchedCount);
        self::assertSame(FacebookPublicationStatus::Failed, $failed->getStatus());
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(PublishFacebookContent::class, $message);
        self::assertSame($pendingId, $message->publicationId);
    }

    private function publication(\DateTimeImmutable $createdAt): FacebookPublication
    {
        return new FacebookPublication(
            FacebookPublicationSourceType::Hike,
            random_int(10_000_000, 2_000_000_000),
            'Une publication Facebook à réconcilier.',
            'https://estela-exploration.fr/randonnees/reconciliation-integration',
            $createdAt,
        );
    }
}
