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

    public function testPromotingLinkOutsideCollectionDoesNotNormalizeAnyRole(): void
    {
        $localCover = $this->link(MediaType::Image, MediaRole::Cover);
        $localGallery = $this->link(MediaType::Image, MediaRole::Gallery);
        $foreignLink = $this->link(MediaType::Image, MediaRole::Gallery);

        (new StudioMediaHelperTraitHarness())->promote([$localCover, $localGallery], $foreignLink);

        self::assertSame(MediaRole::Cover, $localCover->getRole());
        self::assertSame(MediaRole::Gallery, $localGallery->getRole());
        self::assertSame(MediaRole::Gallery, $foreignLink->getRole());
    }

    public function testMetadataNormalizationKeepsFlatScalarsAndDropsStructuredValues(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            self::assertSame([
                'path' => '/uploads/original.jpg',
                'width' => 4096,
                'ratio' => 2.0,
                'sanitized' => true,
                'mobilePath' => null,
            ], (new StudioMediaHelperTraitHarness())->normalizeMetadata([
                'path' => '/uploads/original.jpg',
                'width' => 4096,
                'ratio' => 2.0,
                'sanitized' => true,
                'mobilePath' => null,
                'nested' => ['unexpected' => true],
                'object' => new \stdClass(),
                'resource' => $resource,
                12 => 'numeric key',
            ]));
        } finally {
            fclose($resource);
        }
    }

    public function testStructuredAndOverflowingValuesAreNotConvertedToUsableIdentifiers(): void
    {
        $helper = new StudioMediaHelperTraitHarness();

        self::assertNull($helper->nullableIntValue(['12']));
        self::assertNull($helper->nullableIntValue((string) PHP_INT_MAX.'0'));
        self::assertSame(42, $helper->nullableIntValue('00042'));
        self::assertFalse($helper->validAssociation(['point:42'], new \stdClass(), true));
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

    /** @return array<string, bool|float|int|string|null>|null */
    public function normalizeMetadata(mixed $metadata): ?array
    {
        return $this->normalizeMediaMetadata($metadata);
    }

    public function nullableIntValue(mixed $value): ?int
    {
        return $this->nullableInt($value);
    }

    public function validAssociation(mixed $association, ?object $targetPoint, bool $allowMain): bool
    {
        return $this->isValidStudioMediaAssociation($association, $targetPoint, $allowMain);
    }
}
