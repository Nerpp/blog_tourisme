<?php

namespace App\Tests\Unit;

use App\Controller\Admin\Studio\StudioMediaHelperTrait;
use App\Entity\MediaAsset;
use App\Entity\PlaceMedia;
use App\Enum\MediaRole;
use App\Enum\MediaType;
use PHPUnit\Framework\TestCase;

final class StudioMediaHelperTraitTest extends TestCase
{
    public function testPromotingClassicImageKeepsSingleImageCoverAndRejectsVideoCover(): void
    {
        $oldCover = $this->link(MediaType::Image, MediaRole::Cover);
        $selectedImage = $this->link(MediaType::Image, MediaRole::Gallery);
        $video = $this->link(MediaType::Video, MediaRole::Cover);
        $helper = new StudioMediaHelperTraitHarness();

        $helper->promote([$oldCover, $selectedImage, $video], $selectedImage);

        self::assertSame(MediaRole::Gallery, $oldCover->getRole());
        self::assertSame(MediaRole::Cover, $selectedImage->getRole());
        self::assertSame(MediaRole::Cover, $video->getRole());

        $helper->promote([$oldCover, $selectedImage, $video], $video);

        self::assertSame(MediaRole::Cover, $selectedImage->getRole());
        self::assertSame(MediaRole::Gallery, $video->getRole());
    }

    public function testNormalizingClassicCoversKeepsFirstCoverAndPreservesGallery(): void
    {
        $firstCover = $this->link(MediaType::Image, MediaRole::Cover);
        $duplicateCover = $this->link(MediaType::Image, MediaRole::Cover);
        $gallery = $this->link(MediaType::Image, MediaRole::Gallery);

        (new StudioMediaHelperTraitHarness())->normalize([$firstCover, $duplicateCover, $gallery]);

        self::assertSame(MediaRole::Cover, $firstCover->getRole());
        self::assertSame(MediaRole::Gallery, $duplicateCover->getRole());
        self::assertSame(MediaRole::Gallery, $gallery->getRole());
    }

    private function link(MediaType $mediaType, MediaRole $role): PlaceMedia
    {
        $media = (new MediaAsset())->setMediaType($mediaType);

        return (new PlaceMedia())
            ->setMediaAsset($media)
            ->setRole($role);
    }
}

final class StudioMediaHelperTraitHarness
{
    use StudioMediaHelperTrait;

    /** @param iterable<mixed> $links */
    public function promote(iterable $links, object $selected): void
    {
        $this->promoteClassicImageToCover($links, $selected);
    }

    /** @param iterable<mixed> $links */
    public function normalize(iterable $links): void
    {
        $this->normalizeClassicCoverImages($links);
    }
}
