<?php

namespace App\Service\Facebook;

use App\Entity\CityVisitDraft;
use App\Entity\FacebookPublication;
use App\Entity\HikeDraft;
use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Message\PublishFacebookContent;
use App\Repository\FacebookPublicationRepository;
use App\Service\Seo\PublicUrlGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class FacebookPublicationScheduler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FacebookPublicationRepository $publicationRepository,
        private PublicUrlGenerator $publicUrlGenerator,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates the immutable publication snapshot inside the Estela publication transaction.
     * Dispatch must happen only after that transaction has committed.
     */
    public function prepareFirstPublication(HikeDraft|CityVisitDraft $content): ?FacebookPublication
    {
        $sourceId = $content->getId();
        if (null === $sourceId) {
            throw new LogicException('Le contenu doit être persisté avant de programmer Facebook.');
        }

        $sourceType = $content instanceof HikeDraft
            ? FacebookPublicationSourceType::Hike
            : FacebookPublicationSourceType::CityVisit;
        if ($this->publicationRepository->findOneBySource($sourceType, $sourceId) instanceof FacebookPublication) {
            return null;
        }

        $title = trim((string) $content->getTitle());
        $slug = trim((string) $content->getSlug());
        if ('' === $title || '' === $slug) {
            throw new LogicException('Le contenu Facebook doit posséder un titre et un slug.');
        }

        $publication = new FacebookPublication(
            $sourceType,
            $sourceId,
            $this->message($sourceType, $title),
            $this->publicUrlGenerator->generate(
                FacebookPublicationSourceType::Hike === $sourceType
                    ? 'app_hike_show'
                    : 'app_city_visit_show',
                ['slug' => $slug],
            ),
        );
        $this->entityManager->persist($publication);

        return $publication;
    }

    /**
     * The publication must already be committed. A queue failure is recorded on
     * the durable pending row and never becomes an HTTP error for the caller.
     */
    public function dispatchAfterCommit(FacebookPublication $publication): bool
    {
        $publicationId = $publication->getId();
        if (null === $publicationId || FacebookPublicationStatus::Pending !== $publication->getStatus()) {
            return false;
        }

        try {
            $this->messageBus->dispatch(PublishFacebookContent::forPublication($publicationId));
            $publication->markDispatched()->clearLastError();
            $this->entityManager->flush();

            $this->logger->info('Publication Facebook programmée.', $this->logContext($publication));

            return true;
        } catch (\Throwable $exception) {
            $publication->markDispatchFailed();

            try {
                $this->entityManager->flush();
            } catch (\Throwable) {
                // The Estela transaction is already committed; never turn this into an HTTP failure.
            }

            $this->logger->warning('Échec de programmation Facebook après commit.', [
                ...$this->logContext($publication),
                'error_code' => 'dispatch_failed',
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function recoverAndDispatch(int $publicationId, DateTimeImmutable $pendingCutoff): bool
    {
        /** @var FacebookPublication|null $publication */
        $publication = $this->entityManager->wrapInTransaction(function () use ($publicationId, $pendingCutoff): ?FacebookPublication {
            $publication = $this->publicationRepository->findOneForUpdate($publicationId);
            if (!$publication instanceof FacebookPublication || FacebookPublicationStatus::Pending !== $publication->getStatus()) {
                return null;
            }

            $lastDispatchedAt = $publication->getLastDispatchedAt();
            if (
                (null === $lastDispatchedAt && $publication->getCreatedAt() > $pendingCutoff)
                || (null !== $lastDispatchedAt && $lastDispatchedAt > $pendingCutoff)
            ) {
                return null;
            }

            return $publication;
        });

        return $publication instanceof FacebookPublication && $this->dispatchAfterCommit($publication);
    }

    private function message(FacebookPublicationSourceType $sourceType, string $title): string
    {
        return FacebookPublicationSourceType::Hike === $sourceType
            ? sprintf(
                "Nouvelle randonnée sur Estela Exploration 🏔️\n\n%s\n\nDécouvrez l’itinéraire et toutes les informations sur Estela Exploration.",
                $title,
            )
            : sprintf(
                "Nouvelle visite sur Estela Exploration 🏛️\n\n%s\n\nDécouvrez la visite et toutes les informations sur Estela Exploration.",
                $title,
            );
    }

    /** @return array<string, int|string> */
    private function logContext(FacebookPublication $publication): array
    {
        return [
            'facebook_publication_id' => $publication->getId() ?? 0,
            'source_type' => $publication->getSourceType()->value,
            'source_id' => $publication->getSourceId(),
            'status' => $publication->getStatus()->value,
            'attempt_count' => $publication->getAttemptCount(),
        ];
    }
}
