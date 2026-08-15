<?php

namespace App\Tests\Unit\Facebook;

use App\Entity\CityVisitDraft;
use App\Entity\FacebookPublication;
use App\Entity\HikeDraft;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Message\PublishFacebookContent;
use App\Repository\FacebookPublicationRepository;
use App\Service\Facebook\FacebookPublicationScheduler;
use App\Service\Seo\PublicUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class FacebookPublicationSchedulerTest extends TestCase
{
    public function testItPreparesAHikeSnapshot(): void
    {
        $hike = (new HikeDraft())
            ->setTitle('Le pic des Trois Seigneurs')
            ->setSlug('pic-trois-seigneurs');
        $this->setId($hike, 101);
        $repository = $this->repositoryWithoutPublication(FacebookPublicationSourceType::Hike, 101);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(FacebookPublication::class));
        $router = $this->route(
            'app_hike_show',
            'pic-trois-seigneurs',
            '/randonnees/pic-trois-seigneurs',
        );

        $publication = $this->scheduler($entityManager, $repository, router: $router)
            ->prepareFirstPublication($hike);

        self::assertInstanceOf(FacebookPublication::class, $publication);
        self::assertSame(FacebookPublicationSourceType::Hike, $publication->getSourceType());
        self::assertSame(101, $publication->getSourceId());
        self::assertSame(FacebookPublicationStatus::Pending, $publication->getStatus());
        self::assertSame(
            "Nouvelle randonnée sur Estela Exploration 🏔️\n\nLe pic des Trois Seigneurs\n\nDécouvrez l’itinéraire et toutes les informations sur Estela Exploration.",
            $publication->getMessage(),
        );
        self::assertSame(
            'https://estela-exploration.fr/randonnees/pic-trois-seigneurs',
            $publication->getLink(),
        );
    }

    public function testItPreparesACityVisitSnapshot(): void
    {
        $cityVisit = (new CityVisitDraft())
            ->setTitle('Le centre historique de Perpignan')
            ->setSlug('centre-historique-perpignan');
        $this->setId($cityVisit, 202);
        $repository = $this->repositoryWithoutPublication(FacebookPublicationSourceType::CityVisit, 202);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $router = $this->route(
            'app_city_visit_show',
            'centre-historique-perpignan',
            '/visites-de-ville/centre-historique-perpignan',
        );

        $publication = $this->scheduler($entityManager, $repository, router: $router)
            ->prepareFirstPublication($cityVisit);

        self::assertInstanceOf(FacebookPublication::class, $publication);
        self::assertSame(FacebookPublicationSourceType::CityVisit, $publication->getSourceType());
        self::assertStringContainsString('Nouvelle visite sur Estela Exploration', $publication->getMessage());
        self::assertStringContainsString('Le centre historique de Perpignan', $publication->getMessage());
        self::assertSame(
            'https://estela-exploration.fr/visites-de-ville/centre-historique-perpignan',
            $publication->getLink(),
        );
    }

    public function testPreparingTheSameHikeTwicePersistsOnlyOnePublication(): void
    {
        $hike = (new HikeDraft())->setTitle('Randonnée unique')->setSlug('randonnee-unique');
        $this->setId($hike, 303);
        $stored = null;
        $repository = $this->createMock(FacebookPublicationRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneBySource')
            ->with(FacebookPublicationSourceType::Hike, 303)
            ->willReturnCallback(static function () use (&$stored): ?FacebookPublication {
                return $stored;
            });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (FacebookPublication $publication) use (&$stored): void {
                $stored = $publication;
            });

        $scheduler = $this->scheduler(
            $entityManager,
            $repository,
            router: $this->route('app_hike_show', 'randonnee-unique', '/randonnees/randonnee-unique'),
        );

        self::assertInstanceOf(FacebookPublication::class, $scheduler->prepareFirstPublication($hike));
        self::assertNull($scheduler->prepareFirstPublication($hike));
    }

    public function testItDispatchesOnlyAfterThePublicationHasAnId(): void
    {
        $publication = $this->publication(404);
        $this->setId($publication, 404);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message): Envelope {
                self::assertInstanceOf(PublishFacebookContent::class, $message);
                self::assertSame(404, $message->publicationId);
                self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $message->executionId);

                return new Envelope($message);
            });

        self::assertTrue($this->scheduler(
            $entityManager,
            messageBus: $messageBus,
        )->dispatchAfterCommit($publication));
        self::assertNotNull($publication->getLastDispatchedAt());
        self::assertNull($publication->getLastError());
    }

    public function testADispatchFailureLeavesTheDurablePublicationPendingWithoutLeakingDetails(): void
    {
        $publication = $this->publication(505);
        $this->setId($publication, 505);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Transport secret test-facebook-page-token'));

        self::assertFalse($this->scheduler(
            $entityManager,
            messageBus: $messageBus,
        )->dispatchAfterCommit($publication));
        self::assertSame(FacebookPublicationStatus::Pending, $publication->getStatus());
        self::assertNull($publication->getLastDispatchedAt());
        self::assertSame('dispatch_failed', $publication->getLastErrorCode());
        self::assertStringNotContainsString('test-facebook-page-token', (string) $publication->getLastError());
    }

    private function publication(int $sourceId): FacebookPublication
    {
        return new FacebookPublication(
            FacebookPublicationSourceType::Hike,
            $sourceId,
            'Une randonnée.',
            'https://estela-exploration.fr/randonnees/test',
        );
    }

    private function repositoryWithoutPublication(
        FacebookPublicationSourceType $sourceType,
        int $sourceId,
    ): FacebookPublicationRepository&MockObject {
        $repository = $this->createMock(FacebookPublicationRepository::class);
        $repository->expects(self::once())
            ->method('findOneBySource')
            ->with($sourceType, $sourceId)
            ->willReturn(null);

        return $repository;
    }

    private function route(string $name, string $slug, string $path): RouterInterface&MockObject
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with($name, ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn($path);

        return $router;
    }

    private function scheduler(
        ?EntityManagerInterface $entityManager = null,
        ?FacebookPublicationRepository $repository = null,
        ?MessageBusInterface $messageBus = null,
        ?RouterInterface $router = null,
    ): FacebookPublicationScheduler {
        return new FacebookPublicationScheduler(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $repository ?? $this->createStub(FacebookPublicationRepository::class),
            new PublicUrlGenerator(
                $router ?? $this->createStub(RouterInterface::class),
                'https://estela-exploration.fr',
            ),
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
