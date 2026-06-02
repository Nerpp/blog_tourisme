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

    public function testPhpFileRenamedAsJpgIsRejected(): void
    {
        $path = $this->workspace.'/avatar.jpg';
        file_put_contents($path, '<?php echo "not an image";');

        $this->expectException(InvalidArgumentException::class);

        $this->service()->upload(new UploadedFile($path, 'avatar.jpg', null, null, true));
    }

    public function testValidPngAvatarIsAcceptedAndConvertedToWebp(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng') || !function_exists('imagewebp')) {
            self::markTestSkipped('GD PNG/WebP support is required for avatar conversion.');
        }

        $path = $this->workspace.'/avatar.png';
        $image = imagecreatetruecolor(80, 80);
        self::assertNotFalse($image);
        imagepng($image, $path);
        imagedestroy($image);

        $publicPath = $this->service()->upload(new UploadedFile($path, 'avatar.png', null, null, true));

        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $publicPath);
        self::assertFileExists($this->workspace.'/public'.$publicPath);
    }

    private function service(): AvatarUploadService
    {
        return new AvatarUploadService($this->workspace);
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
