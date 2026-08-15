<?php

namespace App\Tests\Unit\Social;

use App\Entity\FacebookPublication;
use App\Entity\HikeDraft;
use App\Entity\InstagramPublication;
use App\Entity\InstagramPublicationBatch;
use App\Entity\InstagramPublicationMedia;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Enum\InstagramPublicationSourceType;
use App\Enum\InstagramPublicationStatus;
use App\Message\PublishFacebookContent;
use App\Message\PublishInstagramContent;
use App\Repository\FacebookPublicationRepository;
use App\Repository\InstagramPublicationRepository;
use App\Service\Facebook\FacebookPublicationScheduler;
use App\Service\Instagram\InstagramCaptionBuilder;
use App\Service\Instagram\InstagramMediaBatcher;
use App\Service\Instagram\InstagramMediaCollector;
use App\Service\Instagram\InstagramPublicationScheduler;
use App\Service\Instagram\MediaPublicUrlResolver;
use App\Service\Seo\PublicUrlGenerator;
use App\Service\Social\PreparedSocialPublications;
use App\Service\Social\SocialPublicationCoordinator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class SocialPublicationCoordinatorTest extends TestCase
{
    public function testItPreparesBothPublicationsWhenNeitherMarkerExists(): void
    {
        $hike = $this->hike(101);
        $instagramRepository = $this->createMock(InstagramPublicationRepository::class);
        $instagramRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(InstagramPublicationSourceType::Hike, 101)
            ->willReturn(null);
        $facebookRepository = $this->createMock(FacebookPublicationRepository::class);
        $facebookRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(FacebookPublicationSourceType::Hike, 101)
            ->willReturn(null);
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(InstagramPublication::class));
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(FacebookPublication::class));

        $publications = $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, $instagramRepository),
            $this->facebookScheduler(
                $facebookEntityManager,
                $facebookRepository,
                router: $this->hikeRouter(),
            ),
        )->prepareFirstPublications($hike);

        self::assertInstanceOf(InstagramPublication::class, $publications->instagram);
        self::assertSame(InstagramPublicationStatus::NoMedia, $publications->instagram->getStatus());
        self::assertInstanceOf(FacebookPublication::class, $publications->facebook);
        self::assertSame(FacebookPublicationStatus::Pending, $publications->facebook->getStatus());
        self::assertFalse($publications->isEmpty());
    }

    public function testItPreparesOnlyFacebookWhenInstagramAlreadyExists(): void
    {
        $hike = $this->hike(102);
        $instagramRepository = $this->createMock(InstagramPublicationRepository::class);
        $instagramRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(InstagramPublicationSourceType::Hike, 102)
            ->willReturn(new InstagramPublication(InstagramPublicationSourceType::Hike, 102));
        $facebookRepository = $this->createMock(FacebookPublicationRepository::class);
        $facebookRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(FacebookPublicationSourceType::Hike, 102)
            ->willReturn(null);
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::never())->method('persist');
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::once())->method('persist');

        $publications = $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, $instagramRepository),
            $this->facebookScheduler(
                $facebookEntityManager,
                $facebookRepository,
                router: $this->hikeRouter(),
            ),
        )->prepareFirstPublications($hike);

        self::assertNull($publications->instagram);
        self::assertInstanceOf(FacebookPublication::class, $publications->facebook);
        self::assertFalse($publications->isEmpty());
    }

    public function testItPreparesOnlyInstagramWhenFacebookAlreadyExists(): void
    {
        $hike = $this->hike(103);
        $instagramRepository = $this->createMock(InstagramPublicationRepository::class);
        $instagramRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(InstagramPublicationSourceType::Hike, 103)
            ->willReturn(null);
        $facebookRepository = $this->createMock(FacebookPublicationRepository::class);
        $facebookRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(FacebookPublicationSourceType::Hike, 103)
            ->willReturn(new FacebookPublication(
                FacebookPublicationSourceType::Hike,
                103,
                'Publication existante.',
                'https://estela-exploration.fr/randonnees/existante',
            ));
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::once())->method('persist');
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::never())->method('persist');

        $publications = $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, $instagramRepository),
            $this->facebookScheduler($facebookEntityManager, $facebookRepository),
        )->prepareFirstPublications($hike);

        self::assertInstanceOf(InstagramPublication::class, $publications->instagram);
        self::assertNull($publications->facebook);
        self::assertFalse($publications->isEmpty());
    }

    public function testItReturnsAnEmptyResultWhenBothMarkersAlreadyExist(): void
    {
        $hike = $this->hike(104);
        $instagramRepository = $this->createMock(InstagramPublicationRepository::class);
        $instagramRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(InstagramPublicationSourceType::Hike, 104)
            ->willReturn(new InstagramPublication(InstagramPublicationSourceType::Hike, 104));
        $facebookRepository = $this->createMock(FacebookPublicationRepository::class);
        $facebookRepository->expects(self::once())
            ->method('findOneBySource')
            ->with(FacebookPublicationSourceType::Hike, 104)
            ->willReturn(new FacebookPublication(
                FacebookPublicationSourceType::Hike,
                104,
                'Publication existante.',
                'https://estela-exploration.fr/randonnees/existante',
            ));
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::never())->method('persist');
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::never())->method('persist');

        $publications = $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, $instagramRepository),
            $this->facebookScheduler($facebookEntityManager, $facebookRepository),
        )->prepareFirstPublications($hike);

        self::assertNull($publications->instagram);
        self::assertNull($publications->facebook);
        self::assertTrue($publications->isEmpty());
    }

    public function testItDispatchesBothPreparedPublications(): void
    {
        $instagramPublication = $this->dispatchableInstagramPublication(201);
        $facebookPublication = $this->facebookPublication(202);
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::once())->method('flush');
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::once())->method('flush');
        $instagramBus = $this->createMock(MessageBusInterface::class);
        $instagramBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message): bool {
                self::assertInstanceOf(PublishInstagramContent::class, $message);
                self::assertSame(201, $message->publicationId);

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $facebookBus = $this->createMock(MessageBusInterface::class);
        $facebookBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message): bool {
                self::assertInstanceOf(PublishFacebookContent::class, $message);
                self::assertSame(202, $message->publicationId);

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, messageBus: $instagramBus),
            $this->facebookScheduler($facebookEntityManager, messageBus: $facebookBus),
        )->dispatchAfterCommit(new PreparedSocialPublications($instagramPublication, $facebookPublication));

        self::assertNotNull($instagramPublication->getLastDispatchedAt());
        self::assertNotNull($facebookPublication->getLastDispatchedAt());
    }

    public function testDispatchingAnEmptyResultIsANoOp(): void
    {
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::never())->method('flush');
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::never())->method('flush');
        $instagramBus = $this->createMock(MessageBusInterface::class);
        $instagramBus->expects(self::never())->method('dispatch');
        $facebookBus = $this->createMock(MessageBusInterface::class);
        $facebookBus->expects(self::never())->method('dispatch');
        $publications = new PreparedSocialPublications(null, null);

        $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, messageBus: $instagramBus),
            $this->facebookScheduler($facebookEntityManager, messageBus: $facebookBus),
        )->dispatchAfterCommit($publications);

        self::assertTrue($publications->isEmpty());
    }

    public function testAnInstagramTransportFailureDoesNotPreventFacebookDispatch(): void
    {
        $instagramPublication = $this->dispatchableInstagramPublication(301);
        $facebookPublication = $this->facebookPublication(302);
        $instagramEntityManager = $this->createMock(EntityManagerInterface::class);
        $instagramEntityManager->expects(self::once())->method('flush');
        $facebookEntityManager = $this->createMock(EntityManagerInterface::class);
        $facebookEntityManager->expects(self::once())->method('flush');
        $instagramBus = $this->createMock(MessageBusInterface::class);
        $instagramBus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Instagram transport unavailable'));
        $facebookBus = $this->createMock(MessageBusInterface::class);
        $facebookBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->coordinator(
            $this->instagramScheduler($instagramEntityManager, messageBus: $instagramBus),
            $this->facebookScheduler($facebookEntityManager, messageBus: $facebookBus),
        )->dispatchAfterCommit(new PreparedSocialPublications($instagramPublication, $facebookPublication));

        self::assertNull($instagramPublication->getLastDispatchedAt());
        self::assertSame('dispatch_failed', $instagramPublication->getLastErrorCode());
        self::assertNotNull($facebookPublication->getLastDispatchedAt());
    }

    private function hike(int $id): HikeDraft
    {
        $hike = (new HikeDraft())
            ->setTitle('Randonnée coordonnée')
            ->setSlug('randonnee-coordonnee');
        $this->setId($hike, $id);

        return $hike;
    }

    private function hikeRouter(): RouterInterface&MockObject
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_hike_show',
                ['slug' => 'randonnee-coordonnee'],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/randonnees/randonnee-coordonnee');

        return $router;
    }

    private function dispatchableInstagramPublication(int $id): InstagramPublication
    {
        $publication = new InstagramPublication(InstagramPublicationSourceType::Hike, $id, 'Légende');
        $batch = new InstagramPublicationBatch($publication, 1, 'Légende');
        new InstagramPublicationMedia(
            $publication,
            $batch,
            null,
            null,
            1,
            1,
            'https://estela-exploration.fr/uploads/media/test.webp',
            'Légende',
        );
        $batch->synchronizeMediaCount();
        $publication->synchronizeCounters();
        $this->setId($publication, $id);

        return $publication;
    }

    private function facebookPublication(int $id): FacebookPublication
    {
        $publication = new FacebookPublication(
            FacebookPublicationSourceType::Hike,
            $id,
            'Une randonnée.',
            'https://estela-exploration.fr/randonnees/test',
        );
        $this->setId($publication, $id);

        return $publication;
    }

    private function coordinator(
        InstagramPublicationScheduler $instagramPublicationScheduler,
        FacebookPublicationScheduler $facebookPublicationScheduler,
    ): SocialPublicationCoordinator {
        return new SocialPublicationCoordinator($instagramPublicationScheduler, $facebookPublicationScheduler);
    }

    private function instagramScheduler(
        EntityManagerInterface $entityManager,
        ?InstagramPublicationRepository $repository = null,
        ?MessageBusInterface $messageBus = null,
    ): InstagramPublicationScheduler {
        $publicUrlGenerator = new PublicUrlGenerator(
            $this->createStub(RouterInterface::class),
            'https://estela-exploration.fr',
        );

        return new InstagramPublicationScheduler(
            $entityManager,
            $repository ?? $this->createStub(InstagramPublicationRepository::class),
            new InstagramMediaCollector(new MediaPublicUrlResolver($publicUrlGenerator)),
            new InstagramMediaBatcher(),
            new InstagramCaptionBuilder(),
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    private function facebookScheduler(
        EntityManagerInterface $entityManager,
        ?FacebookPublicationRepository $repository = null,
        ?MessageBusInterface $messageBus = null,
        ?RouterInterface $router = null,
    ): FacebookPublicationScheduler {
        return new FacebookPublicationScheduler(
            $entityManager,
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
