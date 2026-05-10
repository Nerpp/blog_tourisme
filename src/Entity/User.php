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

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column]
    private bool $trustedCommenter = false;

    #[ORM\Column]
    private int $approvedCommentsCount = 0;

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

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->mediaAssets = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->moderatedComments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
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

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

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
}
