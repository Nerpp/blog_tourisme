<?php

namespace App\Tests\Unit;

use App\Service\Media\PublicMediaPathValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicMediaPathValidatorTest extends TestCase
{
    public function testNullableUploadPathReturnsNullForBlankValue(): void
    {
        self::assertNull($this->validator()->validateNullableUploadPath('   ', 'image'));
    }

    public function testNullableUploadPathAcceptsSafeUploadsPath(): void
    {
        self::assertSame('/uploads/media/photo.jpg', $this->validator()->validateNullableUploadPath('/uploads/media/photo.jpg', 'image'));
    }

    #[DataProvider('unsafeUploadPathProvider')]
    public function testNullableUploadPathRejectsUnsafePaths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator()->validateNullableUploadPath($path, 'image');
    }

    public function testNullableHttpUrlAcceptsHttpAndHttpsUrls(): void
    {
        $validator = $this->validator();

        self::assertSame('https://example.test/video', $validator->validateNullableHttpUrl('https://example.test/video', 'video'));
        self::assertSame('http://example.test/video', $validator->validateNullableHttpUrl('http://example.test/video', 'video'));
        self::assertNull($validator->validateNullableHttpUrl('', 'video'));
    }

    public function testNullableHttpUrlRejectsDangerousUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator()->validateNullableHttpUrl('javascript:alert(1)', 'video');
    }

    public function testSafeMediaUploadPathRequiresMediaDirectory(): void
    {
        $validator = $this->validator();

        self::assertTrue($validator->isSafeMediaUploadPath('/uploads/media/photo.jpg'));
        self::assertFalse($validator->isSafeMediaUploadPath('/uploads/avatar/photo.jpg'));
        self::assertFalse($validator->isSafeMediaUploadPath('/uploads/media/../secret.jpg'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUploadPathProvider(): iterable
    {
        yield 'traversal' => ['/uploads/media/../secret.jpg'];
        yield 'double slash' => ['/uploads//media/photo.jpg'];
        yield 'backslash' => ['/uploads/media\\photo.jpg'];
        yield 'external url' => ['https://example.test/photo.jpg'];
        yield 'relative path' => ['uploads/media/photo.jpg'];
    }

    private function validator(): PublicMediaPathValidator
    {
        return new PublicMediaPathValidator();
    }
}
