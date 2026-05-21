<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\CityVisitDraft;
use App\Entity\HikeDraft;
use App\Entity\PublicationNotificationLog;
use App\Entity\User;
use App\Enum\CityVisitDraftStatus;
use App\Enum\ContentStatus;
use App\Enum\HikeDraftStatus;
use App\Repository\PublicationNotificationLogRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final class PublicationNotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $userRepository,
        private readonly PublicationNotificationLogRepository $notificationLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from,
    ) {
    }

    /**
     * @return array{recipientCount: int, sentCount: int, errorCount: int, skipped: bool, reason: ?string}
     */
    public function sendNewPublicationNotification(object $content): array
    {
        $publication = $this->publicationData($content);
        if ($publication === null) {
            return $this->report(0, 0, 0, true, 'unsupported_content');
        }

        if (!$publication['isPublished']) {
            return $this->report(0, 0, 0, true, 'content_not_public');
        }

        if ($publication['id'] === null) {
            return $this->report(0, 0, 0, true, 'missing_content_id');
        }

        if ($this->notificationLogRepository->hasNotificationBeenSent($publication['type'], $publication['id'])) {
            return $this->report(0, 0, 0, true, 'already_sent');
        }

        $recipients = $this->subscribedRecipients();
        $recipientCount = count($recipients);

        $log = new PublicationNotificationLog($publication['type'], $publication['id'], $recipientCount);
        $this->entityManager->persist($log);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->report(0, 0, 0, true, 'already_sent');
        } catch (Throwable $exception) {
            $this->logger->error('Unable to create publication notification log.', [
                'content_type' => $publication['type'],
                'content_id' => $publication['id'],
                'exception' => $exception::class,
            ]);

            return $this->report($recipientCount, 0, 1, false, 'log_failed');
        }

        $sentCount = 0;
        $errorCount = 0;
        $profileUrl = $this->urlGenerator->generate('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($recipients as $user) {
            try {
                $email = (new TemplatedEmail())
                    ->from($this->from)
                    ->to((string) $user->getEmail())
                    ->subject('Nouvelle publication sur Blog Tourisme')
                    ->htmlTemplate('emails/new_publication.html.twig')
                    ->context([
                        'user' => $user,
                        'content_type_label' => $publication['label'],
                        'content_title' => $publication['title'],
                        'content_summary' => $publication['summary'],
                        'publication_url' => $publication['url'],
                        'profile_url' => $profileUrl,
                    ]);

                $this->mailer->send($email);
                ++$sentCount;
            } catch (Throwable $exception) {
                ++$errorCount;
                $this->logger->error('Unable to send publication notification email.', [
                    'content_type' => $publication['type'],
                    'content_id' => $publication['id'],
                    'user_id' => $user->getId(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return $this->report($recipientCount, $sentCount, $errorCount, false, null);
    }

    /**
     * @return list<User>
     */
    private function subscribedRecipients(): array
    {
        return array_values(array_filter(
            $this->userRepository->findUsersSubscribedToPublicationEmails(),
            static fn (User $user): bool => filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     id: ?int,
     *     title: string,
     *     summary: ?string,
     *     url: string,
     *     isPublished: bool
     * }|null
     */
    private function publicationData(object $content): ?array
    {
        if ($content instanceof Article) {
            $slug = $content->getSlug();

            return [
                'type' => 'article',
                'label' => 'Article',
                'id' => $content->getId(),
                'title' => (string) $content->getTitle(),
                'summary' => $this->plainSummary($content->getExcerpt() ?? $content->getContent()),
                'url' => $this->urlGenerator->generate('app_article_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL),
                'isPublished' => $content->getStatus() === ContentStatus::Published && $content->getPublishedAt() !== null && $slug !== null,
            ];
        }

        if ($content instanceof HikeDraft) {
            $slug = $content->getSlug();

            return [
                'type' => 'hike',
                'label' => 'Randonnée',
                'id' => $content->getId(),
                'title' => (string) $content->getTitle(),
                'summary' => $this->plainSummary($content->getNotes() ?? $this->locationSummary($content->getDetectedCommuneName(), $content->getDetectedDepartmentName(), $content->getDetectedRegionName())),
                'url' => $this->urlGenerator->generate('app_hike_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL),
                'isPublished' => in_array($content->getStatus(), [HikeDraftStatus::Finished, HikeDraftStatus::Converted], true) && $content->getFinishedAt() !== null && $slug !== null,
            ];
        }

        if ($content instanceof CityVisitDraft) {
            $slug = $content->getSlug();

            return [
                'type' => 'city_visit',
                'label' => 'Visite',
                'id' => $content->getId(),
                'title' => (string) $content->getTitle(),
                'summary' => $this->plainSummary($content->getNotes() ?? $this->locationSummary($content->getDetectedCommuneName(), $content->getDetectedDepartmentName(), $content->getDetectedRegionName())),
                'url' => $this->urlGenerator->generate('app_city_visit_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL),
                'isPublished' => in_array($content->getStatus(), [CityVisitDraftStatus::Finished, CityVisitDraftStatus::Converted], true) && $content->getFinishedAt() !== null && $slug !== null,
            ];
        }

        return null;
    }

    private function locationSummary(?string ...$parts): ?string
    {
        $summary = implode(', ', array_filter(array_map(
            static fn (?string $part): string => trim((string) $part),
            $parts,
        )));

        return $summary !== '' ? $summary : null;
    }

    private function plainSummary(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= 220) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 217)).'...';
    }

    /**
     * @return array{recipientCount: int, sentCount: int, errorCount: int, skipped: bool, reason: ?string}
     */
    private function report(int $recipientCount, int $sentCount, int $errorCount, bool $skipped, ?string $reason): array
    {
        return [
            'recipientCount' => $recipientCount,
            'sentCount' => $sentCount,
            'errorCount' => $errorCount,
            'skipped' => $skipped,
            'reason' => $reason,
        ];
    }
}
