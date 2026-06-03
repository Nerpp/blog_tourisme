<?php

namespace App\Tests\Unit\Media;

use App\Service\Media\PublicMediaPathValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicMediaPathValidatorSecurityTest extends TestCase
{
    #[DataProvider('unsafeUploadPaths')]
    public function testUploadPathRejectsCriticalUnsafeInputs(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator()->validateNullableUploadPath($path, 'media');
    }

    public function testSafeMediaPathAllowsOnlyMediaSubdirectory(): void
    {
        $validator = $this->validator();

        self::assertTrue($validator->isSafeMediaUploadPath('/uploads/media/photo.webp'));
        self::assertFalse($validator->isSafeMediaUploadPath('/tmp/photo.webp'));
        self::assertFalse($validator->isSafeMediaUploadPath('/uploads/avatars/photo.webp'));
        self::assertFalse($validator->isSafeMediaUploadPath(null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUploadPaths(): iterable
    {
        yield 'absolute system path' => ['/tmp/photo.jpg'];
        yield 'http url' => ['http://example.test/photo.jpg'];
        yield 'https url' => ['https://example.test/photo.jpg'];
        yield 'scheme url' => ['php://filter/resource=/uploads/media/photo.jpg'];
        yield 'windows traversal' => ['/uploads/media\\photo.jpg'];
        yield 'double slash' => ['/uploads/media//photo.jpg'];
        yield 'parent traversal' => ['/uploads/media/../../.env'];
    }

    private function validator(): PublicMediaPathValidator
    {
        return new PublicMediaPathValidator();
    }
}
