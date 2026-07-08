<?php

namespace App\Tests\Unit;

use App\Service\ImageUploadSecurity;
use App\Tests\Support\TestImageFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageUploadSecurityTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/blog-tourisme-image-security-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testValidPngUploadIsInspected(): void
    {
        $path = TestImageFactory::createPng($this->workspace, 80, 40, 'photo.png');

        $inspection = (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'photo.png'));

        self::assertSame('image/png', $inspection['mimeType']);
        self::assertGreaterThan(0, $inspection['fileSize']);
        self::assertSame(80, $inspection['width']);
        self::assertSame(40, $inspection['height']);
        self::assertSame('png', $inspection['extension']);
    }

    public function testJpegAliasUsesCanonicalJpgExtension(): void
    {
        $path = TestImageFactory::createJpeg($this->workspace, 64, 32, 'photo.jpeg');

        $inspection = (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'photo.jpeg'));

        self::assertSame('image/jpeg', $inspection['mimeType']);
        self::assertSame(64, $inspection['width']);
        self::assertSame(32, $inspection['height']);
        self::assertSame('jpg', $inspection['extension']);
    }

    public function testUnsupportedExtensionIsRejectedBeforeImageInspection(): void
    {
        $path = TestImageFactory::createPng($this->workspace, 20, 20, 'photo.png');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JPG, PNG et WebP');

        (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'photo.gif'));
    }

    public function testExtensionMustMatchDetectedMimeType(): void
    {
        $path = TestImageFactory::createPng($this->workspace, 20, 20, 'photo.png');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('l’extension du fichier ne correspond pas');

        (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'photo.jpg'));
    }

    public function testDeclaredAllowedMimeTypeMustMatchImageContents(): void
    {
        $path = TestImageFactory::createJpeg($this->workspace, 64, 32, 'photo.jpg');
        $file = new class($path, 'photo.png', null, null, true) extends UploadedFile {
            public function getMimeType(): ?string
            {
                return 'image/png';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('le type réel de l’image ne correspond pas au fichier envoyé.');

        (new ImageUploadSecurity())->inspect($file);
    }

    public function testNonImageContentIsRejected(): void
    {
        $path = TestImageFactory::createTextFile($this->workspace, 'png', 'not an image');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JPG, PNG et WebP');

        (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'photo.png'));
    }

    public function testEmptyUploadIsRejected(): void
    {
        $path = $this->workspace.'/empty.png';
        touch($path);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('le fichier est vide');

        (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'empty.png'));
    }

    public function testIncompleteUploadIsRejected(): void
    {
        $path = $this->workspace.'/partial.png';
        file_put_contents($path, 'partial upload');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('transfert est incomplet');

        (new ImageUploadSecurity())->inspect(new UploadedFile($path, 'partial.png', null, UPLOAD_ERR_PARTIAL, true));
    }

    public function testPngSignatureWithoutReadableImageIsRejected(): void
    {
        $path = $this->workspace.'/truncated.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contenu du fichier n’est pas une image lisible');

        (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'truncated.png'));
    }

    public function testExcessiveImageDimensionsAreRejectedWithLightweightFixture(): void
    {
        $path = TestImageFactory::createPng($this->workspace, 10_001, 1, 'too-wide.png');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dimensions de l’image sont invalides ou trop grandes');

        (new ImageUploadSecurity())->inspect($this->uploadedFile($path, 'too-wide.png'));
    }

    private function uploadedFile(string $path, string $clientName): UploadedFile
    {
        return new UploadedFile($path, $clientName, null, null, true);
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
