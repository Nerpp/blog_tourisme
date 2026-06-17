<?php

namespace App\Tests\Unit;

use App\Service\AvatarUploadService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AvatarUploadServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/blog-tourisme-avatar-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/public/uploads/avatars', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testSvgAvatarIsRejected(): void
    {
        $path = $this->workspace.'/avatar.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(InvalidArgumentException::class);

        $this->service()->upload(new UploadedFile($path, 'avatar.svg', null, null, true));
    }

    public function testUnsupportedExtensionIsRejected(): void
    {
        $path = $this->workspace.'/avatar.gif';
        file_put_contents($path, 'GIF89a');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Formats acceptés');

        $this->service()->upload(new UploadedFile($path, 'avatar.gif', null, null, true));
    }

    public function testEmptyAvatarIsRejected(): void
    {
        $path = $this->workspace.'/avatar.jpg';
        touch($path);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Le fichier envoyé est vide.');

        $this->service()->upload(new UploadedFile($path, 'avatar.jpg', null, null, true));
    }

    public function testPhpFileRenamedAsJpgIsRejected(): void
    {
        $path = $this->workspace.'/avatar.jpg';
        file_put_contents($path, '<?php echo "not an image";');

        $this->expectException(InvalidArgumentException::class);

        $this->service()->upload(new UploadedFile($path, 'avatar.jpg', null, null, true));
    }

    public function testTooLargeAvatarIsRejected(): void
    {
        $path = $this->workspace.'/avatar.jpg';
        file_put_contents($path, str_repeat('x', 5_242_881));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('L’image de profil ne doit pas dépasser 5 Mo.');

        $this->service()->upload(new UploadedFile($path, 'avatar.jpg', null, null, true));
    }

    public function testValidPngAvatarIsAcceptedAndConvertedToWebp(): void
    {
        $this->requireGd(['imagepng']);
        $path = $this->createImage('avatar.png', 'png', 80, 100);

        $publicPath = $this->service()->upload(new UploadedFile($path, 'avatar.png', null, null, true));

        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $publicPath);
        self::assertFileExists($this->workspace.'/public'.$publicPath);
        self::assertSame([256, 256], array_slice((array) getimagesize($this->workspace.'/public'.$publicPath), 0, 2));
    }

    public function testValidJpegAndWebpAvatarsAreAcceptedWithUniqueNames(): void
    {
        $this->requireGd(['imagejpeg', 'imagewebp']);

        $jpegPath = $this->createImage('avatar.jpg', 'jpeg', 96, 96);
        $webpPath = $this->createImage('avatar.webp', 'webp', 120, 80);

        $firstPublicPath = $this->service()->upload(new UploadedFile($jpegPath, 'avatar.jpg', null, null, true));
        $secondPublicPath = $this->service()->upload(new UploadedFile($webpPath, 'avatar.webp', null, null, true));

        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $firstPublicPath);
        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $secondPublicPath);
        self::assertNotSame($firstPublicPath, $secondPublicPath);
        self::assertFileExists($this->workspace.'/public'.$firstPublicPath);
        self::assertFileExists($this->workspace.'/public'.$secondPublicPath);
    }

    public function testTooSmallAvatarIsRejected(): void
    {
        $this->requireGd(['imagepng']);
        $path = $this->createImage('tiny.png', 'png', 32, 80);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('au moins 64 px');

        $this->service()->upload(new UploadedFile($path, 'tiny.png', null, null, true));
    }

    public function testDeleteOnlyRemovesSafeAvatarPaths(): void
    {
        $avatar = $this->workspace.'/public/uploads/avatars/avatar_keep.webp';
        $outside = $this->workspace.'/public/uploads/not-avatar.webp';
        if (!is_dir(dirname($outside))) {
            mkdir(dirname($outside), 0775, true);
        }
        file_put_contents($avatar, 'avatar');
        file_put_contents($outside, 'outside');
        file_put_contents($this->workspace.'/public/uploads/avatars/.gitkeep', '');

        $service = $this->service();
        $service->delete(null);
        $service->delete('/uploads/not-avatar.webp');
        $service->delete('/uploads/avatars/.gitkeep');
        $service->delete('/uploads/avatars/../not-avatar.webp');

        self::assertFileExists($avatar);
        self::assertFileExists($outside);
        self::assertFileExists($this->workspace.'/public/uploads/avatars/.gitkeep');

        $service->delete('/uploads/avatars/avatar_keep.webp');

        self::assertFileDoesNotExist($avatar);
        self::assertFileExists($outside);
    }

    private function service(): AvatarUploadService
    {
        return new AvatarUploadService($this->workspace);
    }

    /** @param list<string> $requiredFunctions */
    private function requireGd(array $requiredFunctions): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            self::markTestSkipped('GD/WebP support is required for avatar conversion.');
        }

        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                self::markTestSkipped(sprintf('GD function %s is required for this test.', $function));
            }
        }
    }

    private function createImage(string $filename, string $format, int $width, int $height): string
    {
        $path = $this->workspace.'/'.$filename;
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 20, 120, 90));

        $written = match ($format) {
            'jpeg' => imagejpeg($image, $path, 90),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 90),
            default => false,
        };
        imagedestroy($image);

        self::assertTrue($written);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } elseif (is_file($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
