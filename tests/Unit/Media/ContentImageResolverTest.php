<?php

namespace App\Tests\Unit\Media;

use App\Entity\Article;
use App\Entity\ArticleMedia;
use App\Entity\HikeDraft;
use App\Entity\HikeDraftMedia;
use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use App\Service\Media\ContentImageResolver;
use PHPUnit\Framework\TestCase;

final class ContentImageResolverTest extends TestCase
{
    public function testCoverWinsFeaturedImageAndGallery(): void
    {
        $cover = $this->image('/uploads/cover.webp');
        $featured = $this->image('/uploads/featured.webp');
        $gallery = $this->image('/uploads/gallery.webp');
        $article = (new Article())->setFeaturedImage($featured);
        $article->getMediaLinks()->add($this->articleLink($article, $gallery, MediaRole::Gallery));
        $article->getMediaLinks()->add($this->articleLink($article, $cover, MediaRole::Cover));

        self::assertSame($cover, (new ContentImageResolver())->resolve($article));
    }

    public function testFeaturedImageWinsGalleryWhenThereIsNoCover(): void
    {
        $featured = $this->image('/uploads/featured.webp');
        $gallery = $this->image('/uploads/gallery.webp');
        $article = (new Article())->setFeaturedImage($featured);
        $article->getMediaLinks()->add($this->articleLink($article, $gallery, MediaRole::Gallery));

        self::assertSame($featured, (new ContentImageResolver())->resolve($article));
    }

    public function testFirstRenderableGalleryIsAnOptionalFallback(): void
    {
        $emptyImage = (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard);
        $gallery = $this->image('/uploads/gallery.webp');
        $hike = new HikeDraft();
        $hike->getMediaLinks()->add($this->hikeLink($hike, $emptyImage, MediaRole::Gallery));
        $hike->getMediaLinks()->add($this->hikeLink($hike, $gallery, MediaRole::Gallery));

        $resolver = new ContentImageResolver();

        self::assertSame($gallery, $resolver->resolve($hike));
        self::assertNull($resolver->resolve($hike, allowGalleryFallback: false));
    }

    public function testContentImagesAndVideosAreNotUsedAsGalleryFallbacks(): void
    {
        $contentImage = $this->image('/uploads/content.webp');
        $video = (new MediaAsset())
            ->setMediaType(MediaType::Video)
            ->setThumbnailPath('/uploads/video.webp');
        $article = new Article();
        $article->getMediaLinks()->add($this->articleLink($article, $contentImage, MediaRole::Content));
        $article->getMediaLinks()->add($this->articleLink($article, $video, MediaRole::Gallery));

        self::assertNull((new ContentImageResolver())->resolve($article));
    }

    private function image(string $path): MediaAsset
    {
        return (new MediaAsset())
            ->setMediaType(MediaType::Image)
            ->setImageType(ImageType::Standard)
            ->setThumbnailPath($path);
    }

    private function articleLink(Article $article, MediaAsset $media, MediaRole $role): ArticleMedia
    {
        return (new ArticleMedia())
            ->setArticle($article)
            ->setMediaAsset($media)
            ->setRole($role);
    }

    private function hikeLink(HikeDraft $hike, MediaAsset $media, MediaRole $role): HikeDraftMedia
    {
        return (new HikeDraftMedia())
            ->setHikeDraft($hike)
            ->setMediaAsset($media)
            ->setRole($role);
    }
}
