<?php

namespace App\Entity;

use App\Repository\TrafficEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrafficEventRepository::class)]
#[ORM\Index(name: 'idx_traffic_event_occurred_at', fields: ['occurredAt'])]
#[ORM\Index(name: 'idx_traffic_event_route', fields: ['routeName'])]
#[ORM\Index(name: 'idx_traffic_event_content', fields: ['contentType', 'contentId'])]
#[ORM\Index(name: 'idx_traffic_event_path', fields: ['path'])]
#[ORM\Index(name: 'idx_traffic_event_status', fields: ['statusCode'])]
#[ORM\Index(name: 'idx_traffic_event_referrer_host', fields: ['referrerHost'])]
#[ORM\Index(name: 'idx_traffic_event_visitor_hash', fields: ['visitorHash'])]
#[ORM\Index(name: 'idx_traffic_event_is_bot', fields: ['isBot'])]
class TrafficEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 10)]
    private string $method = 'GET';

    #[ORM\Column(length: 500)]
    private string $path = '/';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $routeName = null;

    /** @var array<string, scalar|null>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $routeParams = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(nullable: true)]
    private ?int $contentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentTitle = null;

    #[ORM\Column]
    private int $statusCode = 200;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referrerHost = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $deviceType = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $browserFamily = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $osFamily = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $visitorHash = null;

    #[ORM\Column]
    private bool $isBot = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $userAgentHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = strtoupper(substr($method, 0, 10));

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = mb_substr($path, 0, 500);

        return $this;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function setRouteName(?string $routeName): static
    {
        $this->routeName = $routeName === null ? null : mb_substr($routeName, 0, 120);

        return $this;
    }

    /** @return array<string, scalar|null>|null */
    public function getRouteParams(): ?array
    {
        return $this->routeParams;
    }

    /** @param array<string, scalar|null>|null $routeParams */
    public function setRouteParams(?array $routeParams): static
    {
        $this->routeParams = $routeParams;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType === null ? null : mb_substr($contentType, 0, 40);

        return $this;
    }

    public function getContentId(): ?int
    {
        return $this->contentId;
    }

    public function setContentId(?int $contentId): static
    {
        $this->contentId = $contentId;

        return $this;
    }

    public function getContentTitle(): ?string
    {
        return $this->contentTitle;
    }

    public function setContentTitle(?string $contentTitle): static
    {
        $this->contentTitle = $contentTitle === null ? null : mb_substr($contentTitle, 0, 255);

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getReferrerHost(): ?string
    {
        return $this->referrerHost;
    }

    public function setReferrerHost(?string $referrerHost): static
    {
        $this->referrerHost = $referrerHost === null ? null : mb_substr($referrerHost, 0, 255);

        return $this;
    }

    public function setUtmSource(?string $utmSource): static
    {
        $this->utmSource = $utmSource === null ? null : mb_substr($utmSource, 0, 120);

        return $this;
    }

    public function setUtmMedium(?string $utmMedium): static
    {
        $this->utmMedium = $utmMedium === null ? null : mb_substr($utmMedium, 0, 120);

        return $this;
    }

    public function setUtmCampaign(?string $utmCampaign): static
    {
        $this->utmCampaign = $utmCampaign === null ? null : mb_substr($utmCampaign, 0, 180);

        return $this;
    }

    public function getDeviceType(): ?string
    {
        return $this->deviceType;
    }

    public function setDeviceType(?string $deviceType): static
    {
        $this->deviceType = $deviceType;

        return $this;
    }

    public function getBrowserFamily(): ?string
    {
        return $this->browserFamily;
    }

    public function setBrowserFamily(?string $browserFamily): static
    {
        $this->browserFamily = $browserFamily;

        return $this;
    }

    public function getOsFamily(): ?string
    {
        return $this->osFamily;
    }

    public function setOsFamily(?string $osFamily): static
    {
        $this->osFamily = $osFamily;

        return $this;
    }

    public function getVisitorHash(): ?string
    {
        return $this->visitorHash;
    }

    public function setVisitorHash(?string $visitorHash): static
    {
        $this->visitorHash = $visitorHash;

        return $this;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function setIsBot(bool $isBot): static
    {
        $this->isBot = $isBot;

        return $this;
    }

    public function setUserAgentHash(?string $userAgentHash): static
    {
        $this->userAgentHash = $userAgentHash;

        return $this;
    }
}
