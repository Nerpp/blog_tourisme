<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\MediaAssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MediaAssetRepository::class)]
#[ORM\Index(name: 'idx_media_asset_media_type', fields: ['mediaType'])]
#[ORM\Index(name: 'idx_media_asset_image_type', fields: ['imageType'])]
#[ORM\Index(name: 'idx_media_asset_video_type', fields: ['videoType'])]
#[ORM\Index(name: 'idx_media_asset_uploaded_by', fields: ['uploadedBy'])]
#[ORM\HasLifecycleCallbacks]
class MediaAsset
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'mediaAssets')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column(length: 20, enumType: MediaType::class)]
    private MediaType $mediaType = MediaType::Image;

    #[ORM\Column(length: 20, nullable: true, enumType: ImageType::class)]
    private ?ImageType $imageType = null;

    #[ORM\Column(length: 20, nullable: true, enumType: VideoType::class)]
    private ?VideoType $videoType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnailPath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $fileSize = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $projection = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(mappedBy: 'featuredImage', targetEntity: Article::class)]
    private Collection $featuredArticles;

    /** @var Collection<int, Place> */
    #[ORM\OneToMany(mappedBy: 'featuredImage', targetEntity: Place::class)]
    private Collection $featuredPlaces;

    /** @var Collection<int, ArticleMedia> */
    #[ORM\OneToMany(mappedBy: 'mediaAsset', targetEntity: ArticleMedia::class)]
    private Collection $articleLinks;

    /** @var Collection<int, PlaceMedia> */
    #[ORM\OneToMany(mappedBy: 'mediaAsset', targetEntity: PlaceMedia::class)]
    private Collection $placeLinks;

    /** @var Collection<int, HikeDraftMedia> */
    #[ORM\OneToMany(mappedBy: 'mediaAsset', targetEntity: HikeDraftMedia::class)]
    private Collection $hikeDraftLinks;

    /** @var Collection<int, CityVisitDraftMedia> */
    #[ORM\OneToMany(mappedBy: 'mediaAsset', targetEntity: CityVisitDraftMedia::class)]
    private Collection $cityVisitDraftLinks;

    /** @var Collection<int, HikePointMedia> */
    #[ORM\OneToMany(mappedBy: 'mediaAsset', targetEntity: HikePointMedia::class)]
    private Collection $hikePointLinks;

    /** @var Collection<int, CityVisitPointMedia> */
    #[ORM\OneToMany(mappedBy: 'mediaAsset', targetEntity: CityVisitPointMedia::class)]
    private Collection $cityVisitPointLinks;

    public function __construct()
    {
        $this->featuredArticles = new ArrayCollection();
        $this->featuredPlaces = new ArrayCollection();
        $this->articleLinks = new ArrayCollection();
        $this->placeLinks = new ArrayCollection();
        $this->hikeDraftLinks = new ArrayCollection();
        $this->cityVisitDraftLinks = new ArrayCollection();
        $this->hikePointLinks = new ArrayCollection();
        $this->cityVisitPointLinks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->title
            ?? $this->filePath
            ?? $this->externalUrl
            ?? sprintf('Media #%d', $this->id ?? 0);
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): static
    {
        $this->altText = $altText;

        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): static
    {
        $this->caption = $caption;

        return $this;
    }

    public function getMediaType(): MediaType
    {
        return $this->mediaType;
    }

    public function setMediaType(MediaType $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    public function getImageType(): ?ImageType
    {
        return $this->imageType;
    }

    public function setImageType(?ImageType $imageType): static
    {
        $this->imageType = $imageType;

        return $this;
    }

    public function getVideoType(): ?VideoType
    {
        return $this->videoType;
    }

    public function setVideoType(?VideoType $videoType): static
    {
        $this->videoType = $videoType;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): static
    {
        $this->thumbnailPath = $thumbnailPath;

        return $this;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): static
    {
        $this->externalUrl = $externalUrl;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getFileSize(): ?string
    {
        return $this->fileSize;
    }

    public function setFileSize(null|int|string $fileSize): static
    {
        $this->fileSize = $fileSize === null ? null : (string) $fileSize;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getProjection(): ?string
    {
        return $this->projection;
    }

    public function setProjection(?string $projection): static
    {
        $this->projection = $projection;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getFeaturedArticles(): Collection
    {
        return $this->featuredArticles;
    }

    /** @return Collection<int, Place> */
    public function getFeaturedPlaces(): Collection
    {
        return $this->featuredPlaces;
    }

    /** @return Collection<int, ArticleMedia> */
    public function getArticleLinks(): Collection
    {
        return $this->articleLinks;
    }

    /** @return Collection<int, PlaceMedia> */
    public function getPlaceLinks(): Collection
    {
        return $this->placeLinks;
    }

    /** @return Collection<int, HikeDraftMedia> */
    public function getHikeDraftLinks(): Collection
    {
        return $this->hikeDraftLinks;
    }

    /** @return Collection<int, CityVisitDraftMedia> */
    public function getCityVisitDraftLinks(): Collection
    {
        return $this->cityVisitDraftLinks;
    }

    /** @return Collection<int, HikePointMedia> */
    public function getHikePointLinks(): Collection
    {
        return $this->hikePointLinks;
    }

    /** @return Collection<int, CityVisitPointMedia> */
    public function getCityVisitPointLinks(): Collection
    {
        return $this->cityVisitPointLinks;
    }
}
