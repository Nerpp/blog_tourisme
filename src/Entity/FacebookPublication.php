<?php

namespace App\Entity;

use App\Enum\FacebookPublicationSourceType;
use App\Enum\FacebookPublicationStatus;
use App\Repository\FacebookPublicationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: FacebookPublicationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_facebook_publication_source', fields: ['sourceType', 'sourceId'])]
class FacebookPublication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: FacebookPublicationSourceType::class)]
    private FacebookPublicationSourceType $sourceType;

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(length: 500)]
    private string $link;

    #[ORM\Column(length: 20, enumType: FacebookPublicationStatus::class)]
    private FacebookPublicationStatus $status = FacebookPublicationStatus::Pending;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facebookPostId = null;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $firstAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastDispatchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $processingToken = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastErrorCode = null;

    public function __construct(
        FacebookPublicationSourceType $sourceType,
        int $sourceId,
        string $message,
        string $link,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $message = trim($message);
        $link = trim($link);

        if ($sourceId < 1) {
            throw new InvalidArgumentException('The Facebook publication source id must be positive.');
        }

        if ('' === $message) {
            throw new InvalidArgumentException('The Facebook publication message must not be empty.');
        }

        if (!$this->isValidHttpsUrl($link) || mb_strlen($link) > 500) {
            throw new InvalidArgumentException('The Facebook publication link must be a valid HTTPS URL.');
        }

        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->message = $message;
        $this->link = $link;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceType(): FacebookPublicationSourceType
    {
        return $this->sourceType;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function getStatus(): FacebookPublicationStatus
    {
        return $this->status;
    }

    public function isTerminal(): bool
    {
        return FacebookPublicationStatus::Published === $this->status;
    }

    public function getFacebookPostId(): ?string
    {
        return $this->facebookPostId;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function registerAttempt(?DateTimeImmutable $attemptedAt = null): static
    {
        $attemptedAt ??= new DateTimeImmutable();
        ++$this->attemptCount;
        $this->firstAttemptAt ??= $attemptedAt;
        $this->lastAttemptAt = $attemptedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFirstAttemptAt(): ?DateTimeImmutable
    {
        return $this->firstAttemptAt;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastDispatchedAt(): ?DateTimeImmutable
    {
        return $this->lastDispatchedAt;
    }

    public function markDispatched(?DateTimeImmutable $dispatchedAt = null): static
    {
        $this->lastDispatchedAt = $dispatchedAt ?? new DateTimeImmutable();

        return $this;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getProcessingToken(): ?string
    {
        return $this->processingToken;
    }

    public function markProcessing(string $processingToken, ?DateTimeImmutable $attemptedAt = null): static
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $processingToken)) {
            throw new InvalidArgumentException('The Facebook processing token must be a 32-character hexadecimal value.');
        }

        $this->processingToken = $processingToken;
        $this->status = FacebookPublicationStatus::Processing;
        $this->clearLastError();

        return $this->registerAttempt($attemptedAt);
    }

    public function markPublished(string $facebookPostId, ?DateTimeImmutable $publishedAt = null): static
    {
        if (1 !== preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $facebookPostId)) {
            throw new InvalidArgumentException('The Facebook post id is invalid.');
        }

        $publishedAt ??= new DateTimeImmutable();
        $this->facebookPostId = $facebookPostId;
        $this->status = FacebookPublicationStatus::Published;
        $this->lastAttemptAt = $publishedAt;
        $this->publishedAt = $publishedAt;
        $this->processingToken = null;
        $this->clearLastError();

        return $this;
    }

    public function markFailed(
        string $lastError,
        ?string $lastErrorCode = null,
        ?DateTimeImmutable $failedAt = null,
    ): static {
        $this->status = FacebookPublicationStatus::Failed;
        $this->lastAttemptAt = $failedAt ?? new DateTimeImmutable();
        $this->processingToken = null;
        $this->lastError = self::sanitizeError($lastError);
        $this->lastErrorCode = self::normalizeText($lastErrorCode, 100);

        return $this;
    }

    public function markDispatchFailed(): static
    {
        $this->lastError = 'La tâche Facebook n’a pas pu être ajoutée à la file.';
        $this->lastErrorCode = 'dispatch_failed';

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function clearLastError(): static
    {
        $this->lastError = null;
        $this->lastErrorCode = null;

        return $this;
    }

    private function isValidHttpsUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && is_string($parts['host'] ?? null)
            && '' !== $parts['host']
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private static function normalizeText(?string $value, int $maximumLength): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, $maximumLength);
    }

    private static function sanitizeError(string $error): ?string
    {
        $error = trim($error);
        if ('' === $error) {
            return null;
        }

        $error = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $error) ?? $error;
        $error = preg_replace(
            '/([?&](?:access[_-]?token|token|client[_-]?secret|authorization)=)[^&\s]*/i',
            '$1[redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/\b(access[_-]?token|token|client[_-]?secret|authorization)\b\s*[:=]\s*[^\s,;&]+/i',
            '$1=[redacted]',
            $error,
        ) ?? $error;
        $error = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $error) ?? $error;

        return mb_substr(trim($error), 0, 2000);
    }
}
