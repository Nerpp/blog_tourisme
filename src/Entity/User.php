<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Cet email est deja utilise.')]
#[UniqueEntity(fields: ['googleId'], message: 'Ce compte Google est deja associe a un utilisateur.')]
#[UniqueEntity(fields: ['displayName'], message: 'Ce nom d\'utilisateur est deja utilise.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 191, nullable: true, unique: true)]
    private ?string $googleId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank(message: 'Veuillez choisir un nom affiché.')]
    #[Assert\Length(
        min: 3,
        max: 120,
        minMessage: 'Le nom affiché doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le nom affiché ne peut pas dépasser {{ limit }} caractères.'
    )]
    private ?string $displayName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $receivePublicationEmails = false;

    #[ORM\Column]
    private bool $trustedCommenter = false;

    #[ORM\Column]
    private int $approvedCommentsCount = 0;

    #[ORM\Column]
    private int $rejectedCommentsCount = 0;

    #[ORM\Column]
    private bool $isBanned = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $bannedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $banReason = null;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(mappedBy: 'author', targetEntity: Article::class)]
    private Collection $articles;

    /** @var Collection<int, MediaAsset> */
    #[ORM\OneToMany(mappedBy: 'uploadedBy', targetEntity: MediaAsset::class)]
    private Collection $mediaAssets;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'author', targetEntity: Comment::class)]
    private Collection $comments;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(mappedBy: 'moderatedBy', targetEntity: Comment::class)]
    private Collection $moderatedComments;

    /** @var Collection<int, UserModerationWarning> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserModerationWarning::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $moderationWarnings;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->mediaAssets = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->moderatedComments = new ArrayCollection();
        $this->moderationWarnings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->displayName ?? $this->email ?? sprintf('Utilisateur #%d', $this->id ?? 0);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $googleId = trim((string) $googleId);
        $this->googleId = $googleId === '' ? null : mb_substr($googleId, 0, 191);

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    /** @return string */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getMentionHandle(): string
    {
        $source = trim((string) ($this->displayName ?: preg_replace('/@.*/', '', (string) $this->email)));
        if ($source === '') {
            return sprintf('user%d', $this->id ?? 0);
        }

        if (function_exists('transliterator_transliterate')) {
            $source = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $source) ?: mb_strtolower($source);
        } else {
            $source = mb_strtolower($source);
        }

        $handle = preg_replace('/[^a-z0-9_.-]+/', '_', $source) ?? '';
        $handle = trim($handle, '_.-');

        return $handle === '' ? sprintf('user%d', $this->id ?? 0) : $handle;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $avatarPath = trim((string) $avatarPath);
        $this->avatarPath = $avatarPath === '' ? null : mb_substr($avatarPath, 0, 255);

        return $this;
    }

    public function isReceivePublicationEmails(): bool
    {
        return $this->receivePublicationEmails;
    }

    public function setReceivePublicationEmails(bool $receivePublicationEmails): static
    {
        $this->receivePublicationEmails = $receivePublicationEmails;

        return $this;
    }

    public function isTrustedCommenter(): bool
    {
        return $this->trustedCommenter;
    }

    public function setTrustedCommenter(bool $trustedCommenter): static
    {
        $this->trustedCommenter = $trustedCommenter;

        return $this;
    }

    public function getApprovedCommentsCount(): int
    {
        return $this->approvedCommentsCount;
    }

    public function setApprovedCommentsCount(int $approvedCommentsCount): static
    {
        $this->approvedCommentsCount = max(0, $approvedCommentsCount);

        return $this;
    }

    public function incrementApprovedCommentsCount(): static
    {
        ++$this->approvedCommentsCount;

        return $this;
    }

    public function getRejectedCommentsCount(): int
    {
        return $this->rejectedCommentsCount;
    }

    public function setRejectedCommentsCount(int $rejectedCommentsCount): static
    {
        $this->rejectedCommentsCount = max(0, $rejectedCommentsCount);

        return $this;
    }

    public function incrementRejectedCommentsCount(): static
    {
        ++$this->rejectedCommentsCount;

        return $this;
    }

    public function isBanned(): bool
    {
        return $this->isBanned;
    }

    public function setIsBanned(bool $isBanned): static
    {
        $this->isBanned = $isBanned;

        return $this;
    }

    public function getBannedAt(): ?\DateTimeImmutable
    {
        return $this->bannedAt;
    }

    public function setBannedAt(?\DateTimeImmutable $bannedAt): static
    {
        $this->bannedAt = $bannedAt;

        return $this;
    }

    public function getBanReason(): ?string
    {
        return $this->banReason;
    }

    public function setBanReason(?string $banReason): static
    {
        $this->banReason = $banReason === null ? null : mb_substr(trim($banReason), 0, 255);

        return $this;
    }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    /** @return Collection<int, MediaAsset> */
    public function getMediaAssets(): Collection
    {
        return $this->mediaAssets;
    }

    /** @return Collection<int, Comment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    /** @return Collection<int, Comment> */
    public function getModeratedComments(): Collection
    {
        return $this->moderatedComments;
    }

    /** @return Collection<int, UserModerationWarning> */
    public function getModerationWarnings(): Collection
    {
        return $this->moderationWarnings;
    }
}
