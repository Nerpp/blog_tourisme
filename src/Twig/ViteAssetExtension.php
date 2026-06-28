<?php

namespace App\Twig;

use RuntimeException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @phpstan-type ViteManifestChunk array{
 *     file?: string,
 *     src?: string,
 *     name?: string,
 *     isEntry?: bool,
 *     isDynamicEntry?: bool,
 *     imports?: list<string>,
 *     dynamicImports?: list<string>,
 *     css?: list<string>,
 *     assets?: list<string>
 * }
 * @phpstan-type ViteManifest array<string, ViteManifestChunk>
 */
final class ViteAssetExtension extends AbstractExtension
{
    /** @var ViteManifest|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly ?string $devServerUrl = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vite_entry_script_tags', [$this, 'entryScriptTags'], ['is_safe' => ['html']]),
            new TwigFunction('vite_entry_link_tags', [$this, 'entryLinkTags'], ['is_safe' => ['html']]),
            new TwigFunction('vite_entry_deferred_style_tags', [$this, 'entryDeferredStyleTags'], ['is_safe' => ['html']]),
        ];
    }

    public function entryScriptTags(string $entryName): string
    {
        $entry = $this->normalizeEntryName($entryName);

        if ($this->useDevServer()) {
            $server = $this->getDevServerUrl();

            return sprintf(
                '<script type="module" src="%s/@vite/client"></script>'."\n".'<script type="module" src="%s/%s"></script>',
                $this->escapeAttribute($server),
                $this->escapeAttribute($server),
                $this->escapeAttribute(ltrim($entry, '/')),
            );
        }

        $chunk = $this->getManifestEntry($entry);

        return sprintf(
            '<script type="module" src="/build/%s"></script>',
            $this->escapeAttribute($chunk['file'] ?? ''),
        );
    }

    public function entryLinkTags(string $entryName): string
    {
        if ($this->useDevServer()) {
            $stylesheet = $this->resolveDevStylesheetPath($entryName);

            if ($stylesheet === null) {
                return '';
            }

            return sprintf(
                '<link rel="stylesheet" href="%s/%s?direct">',
                $this->escapeAttribute($this->getDevServerUrl()),
                $this->escapeAttribute($stylesheet),
            );
        }

        $entry = $this->normalizeEntryName($entryName);
        $manifest = $this->getManifest();
        $chunk = $this->getManifestEntry($entry);
        $tags = [];

        foreach ($this->collectImportedFiles($manifest, $chunk) as $file) {
            $tags[] = sprintf('<link rel="modulepreload" href="/build/%s">', $this->escapeAttribute($file));
        }

        foreach ($this->collectCssFiles($manifest, $chunk) as $file) {
            $tags[] = sprintf('<link rel="stylesheet" href="/build/%s">', $this->escapeAttribute($file));
        }

        return implode("\n", $tags);
    }

    public function entryDeferredStyleTags(string $entryName): string
    {
        if ($this->useDevServer()) {
            $stylesheet = $this->resolveDevStylesheetPath($entryName);

            return $stylesheet === null
                ? ''
                : $this->deferredStylesheetTags($this->getDevServerUrl().'/'.$stylesheet.'?direct');
        }

        $entry = $this->normalizeEntryName($entryName);
        $manifest = $this->getManifest();
        $chunk = $this->getManifestEntry($entry);
        $tags = [];

        foreach ($this->collectCssFiles($manifest, $chunk) as $file) {
            $tags[] = $this->deferredStylesheetTags('/build/'.$file);
        }

        return implode("\n", $tags);
    }

    private function normalizeEntryName(string $entryName): string
    {
        if (str_contains($entryName, '/') || str_ends_with($entryName, '.js')) {
            return ltrim($entryName, '/');
        }

        return sprintf('assets/%s.js', $entryName);
    }

    private function resolveDevStylesheetPath(string $entryName): ?string
    {
        $entry = $this->normalizeEntryName($entryName);

        if (str_ends_with($entry, '.css')) {
            $stylesheet = $entry;
        } else {
            $stylesheet = sprintf(
                'assets/styles/%s.css',
                pathinfo($entry, PATHINFO_FILENAME),
            );
        }

        return is_file($this->projectDir.'/'.$stylesheet) ? $stylesheet : null;
    }

    private function useDevServer(): bool
    {
        return $this->environment === 'dev' && $this->getDevServerUrl() !== '';
    }

    private function getDevServerUrl(): string
    {
        return rtrim((string) $this->devServerUrl, '/');
    }

    /** @return ViteManifestChunk */
    private function getManifestEntry(string $entry): array
    {
        $manifest = $this->getManifest();

        if (!isset($manifest[$entry])) {
            throw new RuntimeException(sprintf('Vite entry "%s" is missing from public/build/manifest.json. Run "npm run build".', $entry));
        }

        return $manifest[$entry];
    }

    /** @return ViteManifest */
    private function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = $this->projectDir.'/public/build/manifest.json';

        if (!is_file($manifestPath)) {
            throw new RuntimeException('Vite manifest not found at public/build/manifest.json. Run "npm run build" or enable the Vite dev server.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($manifest)) {
            throw new RuntimeException('Vite manifest is not valid.');
        }

        /** @var ViteManifest $manifest */
        return $this->manifest = $manifest;
    }

    /**
     * @param ViteManifest $manifest
     * @param ViteManifestChunk $chunk
     * @param array<string, true> $seen
     *
     * @return list<string>
     */
    private function collectImportedFiles(array $manifest, array $chunk, array &$seen = []): array
    {
        $files = [];

        foreach ($chunk['imports'] ?? [] as $import) {
            if (isset($seen[$import]) || !isset($manifest[$import])) {
                continue;
            }

            $seen[$import] = true;
            $importedChunk = $manifest[$import];

            if (isset($importedChunk['file'])) {
                $files[] = $importedChunk['file'];
            }

            $files = array_merge($files, $this->collectImportedFiles($manifest, $importedChunk, $seen));
        }

        return array_values(array_unique($files));
    }

    /**
     * @param ViteManifest $manifest
     * @param ViteManifestChunk $chunk
     * @param array<string, true> $seen
     *
     * @return list<string>
     */
    private function collectCssFiles(array $manifest, array $chunk, array &$seen = []): array
    {
        $files = $chunk['css'] ?? [];

        foreach ($chunk['imports'] ?? [] as $import) {
            if (isset($seen[$import]) || !isset($manifest[$import])) {
                continue;
            }

            $seen[$import] = true;
            $files = array_merge($files, $this->collectCssFiles($manifest, $manifest[$import], $seen));
        }

        return array_values(array_unique($files));
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function deferredStylesheetTags(string $url): string
    {
        $url = $this->escapeAttribute($url);

        return sprintf(
            '<link rel="stylesheet" href="%s" media="print" onload="this.onload=null;this.media=\'all\'">' .
            '<noscript><link rel="stylesheet" href="%s"></noscript>',
            $url,
            $url,
        );
    }
}
