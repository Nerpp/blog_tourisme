<?php

namespace App\Tests\Integration;

use App\Service\AvatarUploadService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AvatarUploadIntegrationTest extends IntegrationTestCase
{
    private string $workspace;

    /** @var list<string> */
    private array $uploadedPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/blog-tourisme-avatar-integration-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->uploadedPaths)) {
            foreach ($this->uploadedPaths as $publicPath) {
                $this->avatarService()->delete($publicPath);
            }
        }

        if (isset($this->workspace)) {
            $this->removeTree($this->workspace);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{extension: string}>
     */
    public static function validImageProvider(): iterable
    {
        yield 'png' => ['png'];
        yield 'jpg' => ['jpg'];
        yield 'webp' => ['webp'];
    }

    #[DataProvider('validImageProvider')]
    public function testValidAvatarIsConvertedToSquareWebpUsingSymfonyConfiguration(string $extension): void
    {
        $path = $this->createImage($extension, 320, 180);

        $publicPath = $this->avatarService()->upload(new UploadedFile($path, 'avatar.'.$extension, null, null, true));
        $this->uploadedPaths[] = $publicPath;

        self::assertMatchesRegularExpression('#^/uploads/avatars/avatar_[a-f0-9]{32}\.webp$#', $publicPath);

        $absolutePath = static::getContainer()->getParameter('kernel.project_dir').'/public'.$publicPath;
        self::assertFileExists($absolutePath);

        $imageSize = getimagesize($absolutePath);
        self::assertIsArray($imageSize);
        self::assertSame(256, $imageSize[0]);
        self::assertSame(256, $imageSize[1]);
        self::assertSame('image/webp', $imageSize['mime']);
    }

    public function testSvgAvatarIsRejectedUsingSymfonyMimeDetection(): void
    {
        $path = $this->workspace.'/avatar.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(InvalidArgumentException::class);

        $this->avatarService()->upload(new UploadedFile($path, 'avatar.svg', null, null, true));
    }

    public function testPhpFileRenamedAsJpgIsRejectedUsingSymfonyMimeDetection(): void
    {
        $path = $this->workspace.'/avatar.jpg';
        file_put_contents($path, '<?php echo "not an image";');

        $this->expectException(InvalidArgumentException::class);

        $this->avatarService()->upload(new UploadedFile($path, 'avatar.jpg', null, null, true));
    }

    private function avatarService(): AvatarUploadService
    {
        $service = $this->service(AvatarUploadService::class);
        self::assertInstanceOf(AvatarUploadService::class, $service);

        return $service;
    }

    private function createImage(string $extension, int $width, int $height): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD support is required for avatar upload integration tests.');
        }

        $path = sprintf('%s/avatar.%s', $this->workspace, $extension);
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        imagefill($image, 0, 0, imagecolorallocate($image, 48, 120, 180));

        match ($extension) {
            'jpg' => function_exists('imagejpeg') && imagejpeg($image, $path),
            'png' => function_exists('imagepng') && imagepng($image, $path),
            'webp' => function_exists('imagewebp') && imagewebp($image, $path),
            default => false,
        } || self::fail(sprintf('Unable to create %s fixture image.', $extension));

        imagedestroy($image);

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
