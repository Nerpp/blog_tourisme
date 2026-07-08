<?php

namespace App\Tests\Functional;

use App\Entity\Article;
use App\Entity\MediaAsset;
use App\Enum\ContentStatus;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use App\Repository\MediaAssetRepository;

final class AdminMediaControllerTest extends FunctionalTestCase
{
    public function testVerifiedAdminCanOpenMediaIndexAndNewForm(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $media = (new MediaAsset())
            ->setTitle('Média fonctionnel index')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/index-test.jpg');
        $this->persistAndFlush($media);
        $client->loginUser($admin);

        $client->request('GET', '/admin/media');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Média fonctionnel index');

        $client->request('GET', '/admin/media/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nouveau média');
        self::assertSelectorTextContains('body', 'Créer le média');
    }

    public function testVerifiedAdminCanCreateYoutubeVideoMediaWithResolvedThumbnail(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $title = 'Vidéo YouTube fonctionnelle '.$this->uniqueToken('media');
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/media/new');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/admin/media/new', [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'mediaType' => 'video',
            'videoType' => 'youtube',
            'title' => $title,
            'altText' => 'Miniature vidéo',
            'caption' => 'Une vidéo de test.',
            'externalUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        self::assertResponseRedirects('/admin/media');
        $media = $this->mediaRepository()->findOneBy(['title' => $title]);
        self::assertInstanceOf(MediaAsset::class, $media);
        self::assertSame(MediaType::Video, $media->getMediaType());
        self::assertSame(VideoType::Youtube, $media->getVideoType());
        self::assertSame($admin->getId(), $media->getUploadedBy()?->getId());
        self::assertSame('https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $media->getThumbnailPath());
    }

    public function testVerifiedAdminCanEditImageMedia(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $media = (new MediaAsset())
            ->setTitle('Ancien titre média')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/old-image.jpg');
        $this->persistAndFlush($media);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/media/%d/edit', $media->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Modifier le média');

        $client->request('POST', sprintf('/admin/media/%d/edit', $media->getId()), [
            '_token' => $this->inputValue($crawler, 'input[name="_token"]'),
            'mediaType' => 'image',
            'imageType' => 'panorama',
            'title' => 'Nouveau titre média',
            'altText' => 'Texte alternatif média',
            'caption' => 'Légende média',
            'filePath' => '/uploads/media/new-image.jpg',
            'thumbnailPath' => '/uploads/media/new-thumb.jpg',
        ]);

        self::assertResponseRedirects('/admin/media');
        $media = $this->refresh($media);
        self::assertInstanceOf(MediaAsset::class, $media);
        self::assertSame('Nouveau titre média', $media->getTitle());
        self::assertSame(ImageType::Panorama, $media->getImageType());
        self::assertSame('/uploads/media/new-image.jpg', $media->getFilePath());
        self::assertSame('/uploads/media/new-thumb.jpg', $media->getThumbnailPath());
    }

    public function testVerifiedAdminCanDeleteUnusedMediaButNotMediaUsedByArticle(): void
    {
        $client = static::createClient();
        $admin = $this->createVerifiedAdmin();
        $unused = (new MediaAsset())
            ->setTitle('Média supprimable')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/deletable.jpg');
        $used = (new MediaAsset())
            ->setTitle('Média utilisé')
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setFilePath('/uploads/media/used.jpg');
        $article = (new Article())
            ->setAuthor($admin)
            ->setTitle('Article utilisant média')
            ->setSlug('article-utilisant-media-'.$this->uniqueToken('media'))
            ->setContent('<p>Article test.</p>')
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'))
            ->setFeaturedImage($used);
        $this->persistAndFlush($unused, $used, $article);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/media');
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/media/%d/delete', $unused->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/media/%d/delete', $unused->getId())),
        ]);

        self::assertResponseRedirects('/admin/media');
        self::assertNull($this->entityManager()->find(MediaAsset::class, $unused->getId()));

        $crawler = $client->request('GET', '/admin/media');
        self::assertResponseIsSuccessful();
        $client->request('POST', sprintf('/admin/media/%d/delete', $used->getId()), [
            '_token' => $this->tokenFromFormAction($crawler, sprintf('/admin/media/%d/delete', $used->getId())),
        ]);

        self::assertResponseRedirects('/admin/media');
        self::assertNotNull($this->entityManager()->find(MediaAsset::class, $used->getId()));
    }

    private function mediaRepository(): MediaAssetRepository
    {
        $repository = $this->entityManager()->getRepository(MediaAsset::class);
        self::assertInstanceOf(MediaAssetRepository::class, $repository);

        return $repository;
    }
}
