<?php

namespace App\Tests\Unit\Twig;

use App\Twig\ViteAssetExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViteAssetExtensionTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/blog-tourisme-vite-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/public/build', 0775, true);
        mkdir($this->workspace.'/assets/styles', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testDeclaresViteTwigFunctions(): void
    {
        $names = array_map(
            static fn ($function): string => $function->getName(),
            (new ViteAssetExtension($this->workspace, 'test'))->getFunctions(),
        );

        self::assertSame(['vite_entry_script_tags', 'vite_entry_link_tags'], $names);
    }

    public function testProductionScriptAndLinkTagsUseManifestEntryCssAndImports(): void
    {
        $this->writeManifest([
            'assets/app.js' => [
                'file' => 'assets/app-123.js',
                'imports' => ['assets/vendor.js', 'assets/shared.js', 'assets/vendor.js'],
                'css' => ['assets/app.css'],
            ],
            'assets/vendor.js' => [
                'file' => 'assets/vendor-123.js',
                'imports' => ['assets/shared.js'],
                'css' => ['assets/vendor.css'],
            ],
            'assets/shared.js' => [
                'file' => 'assets/shared-123.js',
                'css' => ['assets/shared.css', 'assets/vendor.css'],
            ],
        ]);

        $extension = new ViteAssetExtension($this->workspace, 'prod');

        self::assertSame('<script type="module" src="/build/assets/app-123.js"></script>', $extension->entryScriptTags('app'));
        self::assertSame(
            implode("\n", [
                '<link rel="modulepreload" href="/build/assets/vendor-123.js">',
                '<link rel="modulepreload" href="/build/assets/shared-123.js">',
                '<link rel="stylesheet" href="/build/assets/app.css">',
                '<link rel="stylesheet" href="/build/assets/vendor.css">',
                '<link rel="stylesheet" href="/build/assets/shared.css">',
            ]),
            $extension->entryLinkTags('app'),
        );
    }

    public function testDevServerScriptAndStylesheetTagsAreEscaped(): void
    {
        file_put_contents($this->workspace.'/assets/styles/app.css', 'body{}');
        $extension = new ViteAssetExtension($this->workspace, 'dev', 'http://localhost:5173/');

        self::assertSame(
            '<script type="module" src="http://localhost:5173/@vite/client"></script>'."\n".
            '<script type="module" src="http://localhost:5173/assets/app.js"></script>',
            $extension->entryScriptTags('app'),
        );
        self::assertSame(
            '<link rel="stylesheet" href="http://localhost:5173/assets/styles/app.css?direct">',
            $extension->entryLinkTags('app'),
        );
        self::assertSame('', $extension->entryLinkTags('missing-style'));
    }

    public function testThrowsWhenManifestIsMissingInvalidOrEntryIsAbsent(): void
    {
        $extension = new ViteAssetExtension($this->workspace, 'prod');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vite manifest not found');
        $extension->entryScriptTags('app');
    }

    public function testThrowsWhenManifestJsonIsInvalid(): void
    {
        file_put_contents($this->workspace.'/public/build/manifest.json', '{not json');

        $this->expectException(\JsonException::class);

        (new ViteAssetExtension($this->workspace, 'prod'))->entryScriptTags('app');
    }

    public function testThrowsWhenEntryIsAbsentFromManifest(): void
    {
        $this->writeManifest(['assets/other.js' => ['file' => 'assets/other.js']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vite entry "assets/app.js" is missing');

        (new ViteAssetExtension($this->workspace, 'prod'))->entryScriptTags('app');
    }

    public function testEscapesManifestFileNamesInTags(): void
    {
        $this->writeManifest([
            'assets/app.js' => [
                'file' => 'assets/app"bad.js',
                'css' => ['assets/app"bad.css'],
            ],
        ]);

        $extension = new ViteAssetExtension($this->workspace, 'prod');

        self::assertSame('<script type="module" src="/build/assets/app&quot;bad.js"></script>', $extension->entryScriptTags('/assets/app.js'));
        self::assertSame('<link rel="stylesheet" href="/build/assets/app&quot;bad.css">', $extension->entryLinkTags('/assets/app.js'));
    }

    /** @param array<string, array<string, mixed>> $manifest */
    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->workspace.'/public/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
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
