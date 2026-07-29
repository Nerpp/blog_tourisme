<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ResponsivePlaceholderAssetTest extends TestCase
{
    public function testCommonPlaceholderVariantsAreRealSmallerWebpsWithStableProportions(): void
    {
        $directory = dirname(__DIR__, 2).'/public/images/placeholders';
        $source = $directory.'/destination-card-placeholder.webp';
        $sourceSize = getimagesize($source);
        self::assertIsArray($sourceSize);
        $sourceBytes = filesize($source);
        self::assertIsInt($sourceBytes);

        foreach ([480 => 270, 960 => 540, 1600 => 900] as $width => $height) {
            $variant = sprintf('%s/destination-card-placeholder-%d.webp', $directory, $width);
            $size = getimagesize($variant);

            self::assertIsArray($size);
            self::assertSame('image/webp', $size['mime']);
            self::assertSame($width, $size[0]);
            self::assertSame($height, $size[1]);
            self::assertLessThanOrEqual($sourceSize[0], $size[0]);
            $variantBytes = filesize($variant);
            self::assertIsInt($variantBytes);
            self::assertLessThan($sourceBytes, $variantBytes);
        }
    }
}
