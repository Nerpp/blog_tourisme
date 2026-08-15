<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\FacebookPublication;
use App\Entity\HikeDraft;
use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Enum\CategoryType;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Enum\HikeDraftStatus;
use App\Enum\InstagramPublicationBatchStatus;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Enum\MediaRole;
use App\Message\PublishFacebookContent;
use App\Message\PublishInstagramContent;
use App\Repository\FacebookPublicationRepository;
use App\Repository\InstagramPublicationRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InstagramPublicationWorkflowTest extends FunctionalTestCase
{
    public function testFirstHikePublicationCreatesAndDispatchesExactlyOnce(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $this->linkHikeMedia($hike, $this->createImageMedia('Couverture Instagram randonnée'), MediaRole::Cover);
        $client->loginUser($admin);

        $this->saveHike($client, $hike, HikeDraftStatus::Finished);

        $publication = $this->publicationFor(InstagramPublicationSourceType::Hike, $this->entityId($hike));
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertSame(1, $publication->getTotalMediaCount());
        self::assertSame(1, $publication->getTotalBatchCount());
        $this->assertSingleInstagramMessage($publication);
        $publicationId = $this->entityId($publication);
        $facebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        );
        self::assertSame(FacebookPublicationStatus::Pending, $facebookPublication->getStatus());
        self::assertNotSame('', trim($facebookPublication->getMessage()));
        self::assertStringStartsWith('https://', $facebookPublication->getLink());
        $this->assertSingleFacebookMessage($facebookPublication);
        $facebookPublicationId = $this->entityId($facebookPublication);

        $this->saveHike($client, $hike, HikeDraftStatus::Finished);

        self::assertCount(0, $this->instagramTransport()->getSent());
        self::assertCount(0, $this->facebookTransport()->getSent());
        $samePublication = $this->publicationFor(InstagramPublicationSourceType::Hike, $this->entityId($hike));
        self::assertSame($publicationId, $samePublication->getId());
        self::assertSame(1, $this->publicationRepository()->count([
            'sourceType' => InstagramPublicationSourceType::Hike,
            'sourceId' => $this->entityId($hike),
        ]));
        $sameFacebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        );
        self::assertSame($facebookPublicationId, $sameFacebookPublication->getId());
        self::assertSame(1, $this->facebookPublicationRepository()->count([
            'sourceType' => FacebookPublicationSourceType::Hike,
            'sourceId' => $this->entityId($hike),
        ]));
    }

    public function testFirstCityVisitPublicationCreatesAndDispatchesExactlyOnce(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $cityVisit = $this->createCityVisitDraft($admin);
        $this->linkCityVisitMedia($cityVisit, $this->createImageMedia('Couverture Instagram visite'), MediaRole::Cover);
        $client->loginUser($admin);

        $this->saveCityVisit($client, $cityVisit, CityVisitDraftStatus::Finished);

        $publication = $this->publicationFor(InstagramPublicationSourceType::CityVisit, $this->entityId($cityVisit));
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertSame(1, $publication->getTotalMediaCount());
        self::assertSame(1, $publication->getTotalBatchCount());
        $this->assertSingleInstagramMessage($publication);
        $publicationId = $this->entityId($publication);
        $facebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::CityVisit,
            $this->entityId($cityVisit),
        );
        self::assertSame(FacebookPublicationStatus::Pending, $facebookPublication->getStatus());
        self::assertNotSame('', trim($facebookPublication->getMessage()));
        self::assertStringStartsWith('https://', $facebookPublication->getLink());
        $this->assertSingleFacebookMessage($facebookPublication);
        $facebookPublicationId = $this->entityId($facebookPublication);

        $this->saveCityVisit($client, $cityVisit, CityVisitDraftStatus::Finished);

        self::assertCount(0, $this->instagramTransport()->getSent());
        self::assertCount(0, $this->facebookTransport()->getSent());
        $samePublication = $this->publicationFor(InstagramPublicationSourceType::CityVisit, $this->entityId($cityVisit));
        self::assertSame($publicationId, $samePublication->getId());
        self::assertSame(1, $this->publicationRepository()->count([
            'sourceType' => InstagramPublicationSourceType::CityVisit,
            'sourceId' => $this->entityId($cityVisit),
        ]));
        $sameFacebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::CityVisit,
            $this->entityId($cityVisit),
        );
        self::assertSame($facebookPublicationId, $sameFacebookPublication->getId());
        self::assertSame(1, $this->facebookPublicationRepository()->count([
            'sourceType' => FacebookPublicationSourceType::CityVisit,
            'sourceId' => $this->entityId($cityVisit),
        ]));
    }

    public function testPublicationWithoutCompatibleMediaRecordsInstagramNoMediaAndDispatchesFacebook(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $client->loginUser($admin);

        $this->saveHike($client, $hike, HikeDraftStatus::Finished);

        $publication = $this->publicationFor(InstagramPublicationSourceType::Hike, $this->entityId($hike));
        self::assertSame(InstagramPublicationStatus::NoMedia, $publication->getStatus());
        self::assertSame(0, $publication->getTotalMediaCount());
        self::assertSame(0, $publication->getTotalBatchCount());
        self::assertCount(0, $this->instagramTransport()->getSent());
        $facebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        );
        self::assertSame(FacebookPublicationStatus::Pending, $facebookPublication->getStatus());
        $this->assertSingleFacebookMessage($facebookPublication);
    }

    public function testReturningToDraftThenRepublishingDoesNotCreateOrDispatchAgain(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createHikeDraft($admin);
        $this->linkHikeMedia($hike, $this->createImageMedia('Photo Instagram idempotente'));
        $client->loginUser($admin);

        $this->saveHike($client, $hike, HikeDraftStatus::Finished);
        $publication = $this->publicationFor(InstagramPublicationSourceType::Hike, $this->entityId($hike));
        $publicationId = $this->entityId($publication);
        $this->assertSingleInstagramMessage($publication);
        $facebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        );
        $facebookPublicationId = $this->entityId($facebookPublication);
        $this->assertSingleFacebookMessage($facebookPublication);

        $this->saveHike($client, $hike, HikeDraftStatus::Draft);
        self::assertCount(0, $this->instagramTransport()->getSent());
        self::assertCount(0, $this->facebookTransport()->getSent());

        $this->saveHike($client, $hike, HikeDraftStatus::Finished);

        self::assertCount(0, $this->instagramTransport()->getSent());
        self::assertCount(0, $this->facebookTransport()->getSent());
        $samePublication = $this->publicationFor(InstagramPublicationSourceType::Hike, $this->entityId($hike));
        self::assertSame($publicationId, $samePublication->getId());
        self::assertSame(InstagramPublicationStatus::Pending, $samePublication->getStatus());
        self::assertSame(1, $this->publicationRepository()->count([
            'sourceType' => InstagramPublicationSourceType::Hike,
            'sourceId' => $this->entityId($hike),
        ]));
        $sameFacebookPublication = $this->facebookPublicationFor(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        );
        self::assertSame($facebookPublicationId, $sameFacebookPublication->getId());
        self::assertSame(FacebookPublicationStatus::Pending, $sameFacebookPublication->getStatus());
        self::assertSame(1, $this->facebookPublicationRepository()->count([
            'sourceType' => FacebookPublicationSourceType::Hike,
            'sourceId' => $this->entityId($hike),
        ]));
    }

    public function testPublishingAnArticleDoesNotCreateOrDispatchSocialPublication(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $category = $this->createCategory(CategoryType::Article);
        $publicationCount = $this->publicationRepository()->count([]);
        $facebookPublicationCount = $this->facebookPublicationRepository()->count([]);
        $title = 'Article hors périmètre Instagram '.$this->uniqueToken('article');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/articles/new');
        self::assertResponseIsSuccessful();
        $this->instagramTransport()->reset();
        $this->facebookTransport()->reset();

        $client->request('POST', '/admin/articles/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            '_submission_token' => $this->inputValue($crawler, 'input[name="_submission_token"]'),
            'title' => $title,
            'excerpt' => 'Résumé complet pour vérifier le flux de publication existant.',
            'content' => '<p>Ce contenu éditorial complet ne doit rien programmer sur Instagram.</p>',
            'category' => (string) $category->getId(),
            'publish' => '1',
            'linkedContentType' => 'none',
            'articleRole' => 'related',
        ]);

        self::assertResponseRedirects('/admin/articles');
        $article = $this->entityManager()->getRepository(Article::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Article::class, $article);
        self::assertSame(ContentStatus::Published, $article->getStatus());
        self::assertSame($publicationCount, $this->publicationRepository()->count([]));
        self::assertSame($facebookPublicationCount, $this->facebookPublicationRepository()->count([]));
        self::assertCount(0, $this->instagramTransport()->getSent());
        self::assertCount(0, $this->facebookTransport()->getSent());
    }

    public function testEditingHistoricalPublicContentDoesNotCreateOrDispatchSocialPublication(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $hike = $this->createPublishedHike($admin);
        $cityVisit = $this->createPublishedCityVisit($admin);
        $client->loginUser($admin);

        self::assertNull($this->facebookPublicationRepository()->findOneBySource(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        ));
        $this->saveHike($client, $hike, HikeDraftStatus::Finished);
        self::assertNull($this->facebookPublicationRepository()->findOneBySource(
            FacebookPublicationSourceType::Hike,
            $this->entityId($hike),
        ));
        self::assertCount(0, $this->facebookTransport()->getSent());
        self::assertCount(0, $this->instagramTransport()->getSent());

        self::assertNull($this->facebookPublicationRepository()->findOneBySource(
            FacebookPublicationSourceType::CityVisit,
            $this->entityId($cityVisit),
        ));
        $this->saveCityVisit($client, $cityVisit, CityVisitDraftStatus::Finished);
        self::assertNull($this->facebookPublicationRepository()->findOneBySource(
            FacebookPublicationSourceType::CityVisit,
            $this->entityId($cityVisit),
        ));
        self::assertCount(0, $this->facebookTransport()->getSent());
        self::assertCount(0, $this->instagramTransport()->getSent());
    }

    public function testRetryRouteIsPostOnlyProtectedByAccessAndCsrfAndRetriesOnlyMissingBatches(): void
    {
        $client = static::createClient();
        $sourceAdmin = $this->createVerifiedAdmin();
        $hike = $this->createPublishedHike($sourceAdmin);
        [$publicationId, $publishedBatchId, $failedBatchId] = $this->createRetryablePublication($hike);
        $path = sprintf('/admin/studio/instagram-publications/%d/retry', $publicationId);

        $client->request('POST', $path);
        self::assertResponseRedirects('/login');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $client->request('POST', $path);
        self::assertResponseRedirects('/');

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($this->createVerifiedAdmin());
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(405);

        $this->instagramTransport()->reset();
        $client->request('POST', $path, ['_token' => 'invalid-token']);
        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-instagram', $this->entityId($hike)));
        self::assertSame(
            InstagramPublicationStatus::PartialFailure,
            $this->storedPublication($publicationId)->getStatus(),
        );
        self::assertSame(
            InstagramPublicationBatchStatus::Published,
            $this->storedBatch($publishedBatchId)->getStatus(),
        );
        self::assertSame(
            InstagramPublicationBatchStatus::Failed,
            $this->storedBatch($failedBatchId)->getStatus(),
        );
        self::assertCount(0, $this->instagramTransport()->getSent());

        $token = $this->csrfTokenForClient($client, 'instagram_publication_retry_'.$publicationId);
        $this->instagramTransport()->reset();
        $client->request('POST', $path, ['_token' => $token]);

        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-instagram', $this->entityId($hike)));
        $publication = $this->storedPublication($publicationId);
        self::assertSame(InstagramPublicationStatus::Pending, $publication->getStatus());
        self::assertNull($publication->getLastError());
        $publishedBatch = $this->storedBatch($publishedBatchId);
        self::assertSame(InstagramPublicationBatchStatus::Published, $publishedBatch->getStatus());
        $failedBatch = $this->storedBatch($failedBatchId);
        self::assertSame(InstagramPublicationBatchStatus::Pending, $failedBatch->getStatus());
        self::assertNull($failedBatch->getLastError());
        $this->assertSingleInstagramMessage($publication);

        $secondToken = $this->csrfTokenForClient($client, 'instagram_publication_retry_'.$publicationId);
        $client->request('POST', $path, ['_token' => $secondToken]);
        self::assertResponseRedirects(sprintf('/admin/studio/hikes/%d/edit#section-instagram', $this->entityId($hike)));
        self::assertSame(InstagramPublicationStatus::Pending, $this->storedPublication($publicationId)->getStatus());
        self::assertCount(0, $this->instagramTransport()->getSent());
    }

    private function saveHike(KernelBrowser $client, HikeDraft $hike, HikeDraftStatus $status): void
    {
        $path = sprintf('/admin/studio/hikes/%d/edit', $this->entityId($hike));
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $this->instagramTransport()->reset();
        $this->facebookTransport()->reset();

        $client->request('POST', $path, [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $hike->getTitle(),
            'destination' => '',
            'status' => $status->value,
            'detectedCommuneName' => 'Collioure Instagram',
            'detectedCommuneCode' => '66053',
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'notes' => 'Notes du test de programmation Instagram.',
        ]);

        self::assertResponseRedirects($path.'#section-publication');
    }

    private function saveCityVisit(KernelBrowser $client, CityVisitDraft $cityVisit, CityVisitDraftStatus $status): void
    {
        $path = sprintf('/admin/studio/city-visits/%d/edit', $this->entityId($cityVisit));
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $this->instagramTransport()->reset();
        $this->facebookTransport()->reset();

        $client->request('POST', $path, [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'title' => (string) $cityVisit->getTitle(),
            'destination' => '',
            'status' => $status->value,
            'detectedCommuneName' => 'Perpignan Instagram',
            'detectedCommuneCode' => '66136',
            'detectedDepartmentName' => 'Pyrenees-Orientales',
            'detectedRegionName' => 'Occitanie',
            'notes' => 'Notes du test de programmation Instagram.',
        ]);

        self::assertResponseRedirects($path.'#section-publication');
    }

    private function publicationRepository(): InstagramPublicationRepository
    {
        $repository = static::getContainer()->get(InstagramPublicationRepository::class);
        self::assertInstanceOf(InstagramPublicationRepository::class, $repository);

        return $repository;
    }

    private function facebookPublicationRepository(): FacebookPublicationRepository
    {
        $repository = static::getContainer()->get(FacebookPublicationRepository::class);
        self::assertInstanceOf(FacebookPublicationRepository::class, $repository);

        return $repository;
    }

    private function publicationFor(InstagramPublicationSourceType $sourceType, int $sourceId): InstagramPublication
    {
        $publication = $this->publicationRepository()->findOneBySource($sourceType, $sourceId);
        self::assertInstanceOf(InstagramPublication::class, $publication);

        return $publication;
    }

    private function facebookPublicationFor(
        FacebookPublicationSourceType $sourceType,
        int $sourceId,
    ): FacebookPublication {
        $publication = $this->facebookPublicationRepository()->findOneBySource($sourceType, $sourceId);
        self::assertInstanceOf(FacebookPublication::class, $publication);

        return $publication;
    }

    private function storedPublication(int $publicationId): InstagramPublication
    {
        $publication = $this->entityManager()->find(InstagramPublication::class, $publicationId);
        self::assertInstanceOf(InstagramPublication::class, $publication);

        return $publication;
    }

    private function storedBatch(int $batchId): InstagramPublicationBatch
    {
        $batch = $this->entityManager()->find(InstagramPublicationBatch::class, $batchId);
        self::assertInstanceOf(InstagramPublicationBatch::class, $batch);

        return $batch;
    }

    private function instagramTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.instagram_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function facebookTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.facebook_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function assertSingleInstagramMessage(InstagramPublication $publication): void
    {
        $sent = $this->instagramTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(PublishInstagramContent::class, $message);
        self::assertSame($this->entityId($publication), $message->publicationId);
    }

    private function assertSingleFacebookMessage(FacebookPublication $publication): void
    {
        $sent = $this->facebookTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(PublishFacebookContent::class, $message);
        self::assertSame($this->entityId($publication), $message->publicationId);
    }

    /** @return array{int, int, int} */
    private function createRetryablePublication(HikeDraft $hike): array
    {
        $publishedMedia = $this->createImageMedia('Média du lot Instagram publié');
        $failedMedia = $this->createImageMedia('Média du lot Instagram en échec');
        $publication = (new InstagramPublication(
            InstagramPublicationSourceType::Hike,
            $this->entityId($hike),
            'Publication à reprendre',
        ))
            ->setStatus(InstagramPublicationStatus::PartialFailure)
            ->setLastError('Un lot Instagram reste en échec.');
        $publishedBatch = (new InstagramPublicationBatch($publication, 1, 'Lot déjà publié'))
            ->setStatus(InstagramPublicationBatchStatus::Published);
        new InstagramPublicationMedia(
            $publication,
            $publishedBatch,
            $publishedMedia,
            $publishedMedia->getId(),
            1,
            1,
            'https://cdn.example.test/instagram-published.jpg',
        );
        $failedBatch = (new InstagramPublicationBatch($publication, 2, 'Lot à reprendre'))
            ->setStatus(InstagramPublicationBatchStatus::Failed)
            ->setLastError('Échec du lot de test.');
        $failedPublicationMedia = new InstagramPublicationMedia(
            $publication,
            $failedBatch,
            $failedMedia,
            $failedMedia->getId(),
            2,
            1,
            'https://cdn.example.test/instagram-failed.jpg',
        );
        $failedPublicationMedia->setLastError('Échec du média de test.');
        $publication->synchronizeCounters();
        $this->persistAndFlush($publication);

        return [
            $this->entityId($publication),
            $this->entityId($publishedBatch),
            $this->entityId($failedBatch),
        ];
    }

    private function entityId(
        HikeDraft|CityVisitDraft|FacebookPublication|InstagramPublication|InstagramPublicationBatch $entity,
    ): int
    {
        $id = $entity->getId();
        self::assertNotNull($id);

        return $id;
    }
}
