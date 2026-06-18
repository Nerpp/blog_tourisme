<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\CityVisitDraft;
use App\Entity\CityVisitDraftMedia;
use App\Entity\CityVisitPoint;
use App\Entity\Comment;
use App\Entity\CommentReplyNotification;
use App\Entity\Destination;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\HikePoint;
use App\Entity\MediaAsset;
use App\Entity\Place;
use App\Entity\PlaceMedia;
use App\Entity\User;
use App\Enum\CategoryType;
use App\Enum\CityVisitPointType;
use App\Enum\CityVisitDraftStatus;
use App\Enum\CommentStatus;
use App\Enum\ContentStatus;
use App\Enum\DestinationType;
use App\Enum\HikePointType;
use App\Enum\HikeDraftStatus;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

abstract class FunctionalTestCase extends WebTestCase
{
    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @param list<string> $roles
     */
    protected function createUser(array $roles = ['ROLE_USER'], bool $verified = true, bool $banned = false): User
    {
        $token = $this->uniqueToken('user');
        $user = (new User())
            ->setEmail($token.'@example.test')
            ->setDisplayName('Test '.$token)
            ->setPassword('test-password')
            ->setRoles($roles)
            ->setIsVerified($verified)
            ->setIsBanned($banned);

        if ($banned) {
            $user->setBannedAt(new \DateTimeImmutable());
        }

        $this->persistAndFlush($user);

        return $user;
    }

    protected function createVerifiedAdmin(): User
    {
        return $this->createUser(['ROLE_ADMIN', 'ROLE_USER']);
    }

    protected function createUnverifiedAdmin(): User
    {
        return $this->createUser(['ROLE_ADMIN', 'ROLE_USER'], false);
    }

    protected function createDestination(
        ?string $name = null,
        DestinationType $type = DestinationType::Area,
        ?Destination $parent = null,
        ?string $code = null,
    ): Destination {
        $token = $this->uniqueToken('destination');
        $name ??= 'Destination '.$token;
        $destination = (new Destination())
            ->setName($name)
            ->setSlug($this->slug($name.' '.$token))
            ->setType($type)
            ->setParent($parent)
            ->setCode($code);

        $this->persistAndFlush($destination);

        return $destination;
    }

    protected function createArticle(?User $author = null, ?Destination $destination = null): Article
    {
        $token = $this->uniqueToken('article');
        $article = (new Article())
            ->setAuthor($author)
            ->setTitle('Article test '.$token)
            ->setSlug('article-test-'.$token)
            ->setExcerpt('Extrait de test '.$token)
            ->setContent('<p>Contenu public de test pour les commentaires.</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));

        $this->entityManager()->persist($article);
        $this->entityManager()->flush();

        return $article;
    }

    protected function createComment(User $author, Article $article, CommentStatus $status = CommentStatus::Approved): Comment
    {
        $now = new \DateTimeImmutable('-1 hour');
        $comment = (new Comment())
            ->setAuthor($author)
            ->setArticle($article)
            ->setContent('Commentaire fonctionnel assez long.')
            ->setStatus($status);

        if ($status === CommentStatus::Approved) {
            $comment
                ->setPublishedAt($now)
                ->setApprovedAt($now);
        }

        $this->persistAndFlush($comment);

        return $comment;
    }

    protected function createHikeDraft(User $admin, ?Destination $destination = null): HikeDraft
    {
        $token = $this->uniqueToken('hike');
        $hike = (new HikeDraft())
            ->setTitle('Randonnée test '.$token)
            ->setSlug('randonnee-test-'.$token)
            ->setStatus(HikeDraftStatus::Draft)
            ->setCreatedBy($admin)
            ->setDestination($destination);

        $this->persistAndFlush($hike);

        return $hike;
    }

    protected function createPublishedHike(User $admin, ?Destination $destination = null): HikeDraft
    {
        $hike = $this->createHikeDraft($admin, $destination);
        $hike
            ->setStatus(HikeDraftStatus::Finished)
            ->setFinishedAt(new \DateTimeImmutable('-1 hour'));

        $this->persistAndFlush($hike);

        return $hike;
    }

    protected function createCityVisitDraft(User $admin, ?Destination $destination = null): CityVisitDraft
    {
        $token = $this->uniqueToken('city');
        $cityVisit = (new CityVisitDraft())
            ->setTitle('Visite test '.$token)
            ->setSlug('visite-test-'.$token)
            ->setStatus(CityVisitDraftStatus::Draft)
            ->setCreatedBy($admin)
            ->setDestination($destination);

        $this->persistAndFlush($cityVisit);

        return $cityVisit;
    }

    protected function createPublishedCityVisit(User $admin, ?Destination $destination = null): CityVisitDraft
    {
        $cityVisit = $this->createCityVisitDraft($admin, $destination);
        $cityVisit
            ->setStatus(CityVisitDraftStatus::Finished)
            ->setFinishedAt(new \DateTimeImmutable('-1 hour'));

        $this->persistAndFlush($cityVisit);

        return $cityVisit;
    }

    protected function createPlace(?Destination $destination = null, ?Category $category = null): Place
    {
        $token = $this->uniqueToken('place');
        $place = (new Place())
            ->setName('Lieu test '.$token)
            ->setSlug('lieu-test-'.$token)
            ->setDestination($destination)
            ->setCategory($category)
            ->setStatus(ContentStatus::Draft)
            ->setDifficulty(PlaceDifficulty::Unknown)
            ->setPriceType(PriceType::Unknown);

        $this->persistAndFlush($place);

        return $place;
    }

    protected function createPublishedPlace(?Destination $destination = null, ?Category $category = null): Place
    {
        $place = $this->createPlace($destination, $category);
        $place
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 hour'))
            ->setShortDescription('Description publique du repérage fonctionnel.');

        $this->persistAndFlush($place);

        return $place;
    }

    protected function createCategory(CategoryType $type = CategoryType::Both): Category
    {
        $token = $this->uniqueToken('category');
        $category = (new Category())
            ->setName('Categorie '.$token)
            ->setSlug('categorie-'.$token)
            ->setType($type);

        $this->persistAndFlush($category);

        return $category;
    }

    protected function createImageMedia(?string $title = null): MediaAsset
    {
        $token = $this->uniqueToken('media');
        $media = (new MediaAsset())
            ->setTitle($title ?? 'Image test '.$token)
            ->setAltText('Texte alternatif '.$token)
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/'.$token.'.jpg');

        $this->persistAndFlush($media);

        return $media;
    }

    protected function linkHikeMedia(HikeDraft $hike, MediaAsset $media, MediaRole $role = MediaRole::Gallery, int $position = 0): HikeDraftMedia
    {
        $link = (new HikeDraftMedia())
            ->setHikeDraft($hike)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $hike->addMediaLink($link);
        $media->getHikeDraftLinks()->add($link);
        $this->persistAndFlush($link);

        return $link;
    }

    protected function linkCityVisitMedia(CityVisitDraft $cityVisit, MediaAsset $media, MediaRole $role = MediaRole::Gallery, int $position = 0): CityVisitDraftMedia
    {
        $link = (new CityVisitDraftMedia())
            ->setCityVisitDraft($cityVisit)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $cityVisit->addMediaLink($link);
        $media->getCityVisitDraftLinks()->add($link);
        $this->persistAndFlush($link);

        return $link;
    }

    protected function linkPlaceMedia(Place $place, MediaAsset $media, MediaRole $role = MediaRole::Gallery, int $position = 0): PlaceMedia
    {
        $link = (new PlaceMedia())
            ->setPlace($place)
            ->setMediaAsset($media)
            ->setRole($role)
            ->setPosition($position);
        $place->getMediaLinks()->add($link);
        $media->getPlaceLinks()->add($link);
        if ($role === MediaRole::Cover) {
            $place->setFeaturedImage($media);
        }
        $this->persistAndFlush($link, $place);

        return $link;
    }

    protected function persistAndFlush(object ...$entities): void
    {
        $entityManager = $this->entityManager();
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();
    }

    protected function createHikePoint(HikeDraft $hike, float $latitude = 42.7000, float $longitude = 2.9000, int $position = 1): HikePoint
    {
        $point = (new HikePoint())
            ->setHikeDraft($hike)
            ->setType(HikePointType::Other)
            ->setTitle('Point randonnée '.$position)
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setPosition($position);
        $hike->addPoint($point);

        $this->persistAndFlush($point);

        return $point;
    }

    protected function createCityVisitPoint(CityVisitDraft $cityVisit, float $latitude = 43.6000, float $longitude = 3.8800, int $position = 1): CityVisitPoint
    {
        $point = (new CityVisitPoint())
            ->setCityVisitDraft($cityVisit)
            ->setType(CityVisitPointType::Other)
            ->setTitle('Point visite '.$position)
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setPosition($position);
        $cityVisit->addPoint($point);

        $this->persistAndFlush($point);

        return $point;
    }

    protected function createCommentReplyNotification(User $recipient, Comment $comment, ?User $triggeredBy = null): CommentReplyNotification
    {
        $notification = (new CommentReplyNotification())
            ->setRecipient($recipient)
            ->setComment($comment)
            ->setTriggeredBy($triggeredBy);

        $this->persistAndFlush($notification);

        return $notification;
    }

    protected function refresh(object $entity): object
    {
        if (method_exists($entity, 'getId')) {
            $stored = $this->entityManager()->find($entity::class, $entity->getId());
            self::assertIsObject($stored);

            return $stored;
        }

        $this->entityManager()->refresh($entity);

        return $entity;
    }

    protected function tokenFromFormAction(Crawler $crawler, string $actionContains, string $tokenName = '_token'): string
    {
        foreach ($crawler->filter('form') as $form) {
            $formCrawler = new Crawler($form);
            $action = $formCrawler->attr('action') ?? '';
            if (!str_contains($action, $actionContains)) {
                continue;
            }

            $token = $formCrawler->filter(sprintf('input[name="%s"]', $tokenName));
            if ($token->count() > 0) {
                return $token->attr('value') ?? '';
            }
        }

        self::fail(sprintf('No CSRF token found for form action containing "%s".', $actionContains));
    }

    protected function csrfTokenFor(string $tokenId): string
    {
        $tokenManager = static::getContainer()->get(CsrfTokenManagerInterface::class);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokenManager);

        return $tokenManager->getToken($tokenId)->getValue();
    }

    protected function inputValue(Crawler $crawler, string $selector): string
    {
        $input = $crawler->filter($selector);
        self::assertGreaterThan(0, $input->count(), sprintf('Missing input "%s".', $selector));

        return $input->attr('value') ?? '';
    }

    protected function uniqueToken(string $prefix): string
    {
        return sprintf('%s-%s', $prefix, bin2hex(random_bytes(5)));
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));

        return $slug === '' ? $this->uniqueToken('slug') : $slug;
    }
}
