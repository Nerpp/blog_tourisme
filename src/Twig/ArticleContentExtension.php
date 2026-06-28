<?php

namespace App\Twig;

use App\Entity\Article;
use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Service\Article\ArticleContentSanitizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ArticleContentExtension extends AbstractExtension
{
    public function __construct(
        private readonly ArticleContentSanitizer $sanitizer,
        private readonly MediaImageExtension $mediaImageExtension,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('article_content_html', [$this, 'contentHtml'], ['is_safe' => ['html']]),
            new TwigFunction('article_editor_content_html', [$this, 'editorContentHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function editorContentHtml(Article $article): string
    {
        return $this->sanitizer->sanitize($article->getContent());
    }

    public function contentHtml(Article $article): string
    {
        $content = $this->sanitizer->sanitize($article->getContent());
        if ($content === '') {
            return '';
        }

        $linkedMedia = $this->linkedMediaById($article);
        $rendered = $this->replaceBlockMediaParagraphs($content, $linkedMedia, $article);
        $rendered = $this->replaceRemainingMediaTokens($rendered, $linkedMedia, $article);

        return preg_replace(
            '#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#iu',
            '',
            $rendered,
        ) ?? $rendered;
    }

    /** @return array<int, MediaAsset> */
    private function linkedMediaById(Article $article): array
    {
        $mediaById = [];
        foreach ($article->getMediaLinks() as $link) {
            $media = $link->getMediaAsset();
            if (!$media instanceof MediaAsset) {
                continue;
            }

            if ($media->getId() !== null) {
                $mediaById[$media->getId()] = $media;
            }
        }

        $featuredImage = $article->getFeaturedImage();
        if ($featuredImage instanceof MediaAsset && $featuredImage->getId() !== null) {
            $mediaById[$featuredImage->getId()] = $featuredImage;
        }

        return $mediaById;
    }

    /** @param array<int, MediaAsset> $linkedMedia */
    private function replaceBlockMediaParagraphs(string $content, array $linkedMedia, Article $article): string
    {
        return preg_replace_callback(
            '#<p>(?:\s|&nbsp;|<br\s*/?>)*\[\[media:(\d+)\]\](?:\s|&nbsp;|<br\s*/?>)*</p>#iu',
            function (array $matches) use ($linkedMedia, $article): string {
                return $this->mediaHtml($linkedMedia[(int) $matches[1]] ?? null, $article, inline: false);
            },
            $content,
        ) ?? $content;
    }

    /** @param array<int, MediaAsset> $linkedMedia */
    private function replaceRemainingMediaTokens(string $content, array $linkedMedia, Article $article): string
    {
        return preg_replace_callback(
            '/\[\[media:(\d+)\]\]/',
            function (array $matches) use ($linkedMedia, $article): string {
                return $this->mediaHtml($linkedMedia[(int) $matches[1]] ?? null, $article, inline: true);
            },
            $content,
        ) ?? $content;
    }

    private function mediaHtml(?MediaAsset $media, Article $article, bool $inline): string
    {
        if (!$media instanceof MediaAsset || $media->getMediaType() !== MediaType::Image) {
            return '';
        }

        return $inline ? $this->mediaInlineHtml($media, $article) : $this->mediaFigureHtml($media, $article);
    }

    private function mediaFigureHtml(MediaAsset $media, Article $article): string
    {
        $src = $this->articleImageUrl($media);

        if ($src === null) {
            return '';
        }

        $isStandardImage = $media->getImageType() === ImageType::Standard;
        $avifSrcset = $isStandardImage ? null : $this->mediaImageExtension->imageSrcset($media, 'avif');
        $webpSrcset = $this->mediaImageExtension->imageSrcset($media, 'webp');
        $fallbackSrcset = $isStandardImage ? null : $this->mediaImageExtension->imageSrcset($media, 'fallback');
        $imgSrcset = $isStandardImage ? $this->articleStandardImageSrcset($media) : $fallbackSrcset;
        $dimensions = $this->mediaImageExtension->imageDimensions($media, 'medium')
            ?? $this->mediaImageExtension->imageDimensions($media, 'large')
            ?? $this->mediaImageExtension->imageDimensions($media, 'thumb');
        $title = $this->mediaImageExtension->publicTitle($media, $article, $article->getTitle());
        $alt = $this->mediaImageExtension->publicAlt($media, $article, $title ?? $article->getTitle());

        $html = '<figure class="article-content-media"><picture>';
        $displaySizes = '(min-width: 900px) 640px, calc(100vw - 72px)';
        if ($avifSrcset !== null) {
            $html .= sprintf(
                '<source type="image/avif" srcset="%s" sizes="%s">',
                $this->escape($avifSrcset),
                $displaySizes,
            );
        }

        if (!$isStandardImage && $webpSrcset !== null) {
            $html .= sprintf(
                '<source type="image/webp" srcset="%s" sizes="%s">',
                $this->escape($webpSrcset),
                $displaySizes,
            );
        }

        $html .= sprintf(
            '<img src="%s" alt="%s" loading="lazy" decoding="async"%s%s>',
            $this->escape($src),
            $this->escape($alt),
            $imgSrcset !== null ? sprintf(' srcset="%s" sizes="%s"', $this->escape($imgSrcset), $displaySizes) : '',
            $dimensions !== null ? sprintf(' width="%d" height="%d"', $dimensions['width'], $dimensions['height']) : '',
        );
        $html .= '</picture>';

        if ($title !== null && $title !== '') {
            $html .= sprintf('<figcaption>%s</figcaption>', $this->escape($title));
        }

        return $html.'</figure>';
    }

    private function mediaInlineHtml(MediaAsset $media, Article $article): string
    {
        $src = $this->articleImageUrl($media);

        if ($src === null) {
            return '';
        }

        $isStandardImage = $media->getImageType() === ImageType::Standard;
        $srcset = $isStandardImage
            ? $this->articleStandardImageSrcset($media)
            : ($this->mediaImageExtension->imageSrcset($media, 'fallback') ?? $this->mediaImageExtension->imageSrcset($media, 'webp'));
        $dimensions = $this->mediaImageExtension->imageDimensions($media, 'medium')
            ?? $this->mediaImageExtension->imageDimensions($media, 'large')
            ?? $this->mediaImageExtension->imageDimensions($media, 'thumb');
        $title = $this->mediaImageExtension->publicTitle($media, $article, $article->getTitle());
        $alt = $this->mediaImageExtension->publicAlt($media, $article, $title ?? $article->getTitle());

        return sprintf(
            '<span class="article-content-media-inline"><img src="%s" alt="%s" loading="lazy" decoding="async"%s%s></span>',
            $this->escape($src),
            $this->escape($alt),
            $srcset !== null ? sprintf(' srcset="%s" sizes="(min-width: 900px) 320px, 100vw"', $this->escape($srcset)) : '',
            $dimensions !== null ? sprintf(' width="%d" height="%d"', $dimensions['width'], $dimensions['height']) : '',
        );
    }

    private function articleImageUrl(MediaAsset $media): ?string
    {
        $metadata = $media->getMetadata();
        if (is_array($metadata) && ($metadata['articleResponsiveWebp'] ?? false) === true) {
            return $this->mediaImageExtension->imageUrl($media, 'thumb');
        }

        foreach (['medium', 'large', 'thumb'] as $size) {
            if ($this->mediaImageExtension->imageDimensions($media, $size) === null) {
                continue;
            }

            return $this->mediaImageExtension->imageUrl($media, $size);
        }

        return $this->mediaImageExtension->imageUrl($media, 'thumb');
    }

    private function articleStandardImageSrcset(MediaAsset $media): ?string
    {
        $entries = [];
        $seenWidths = [];

        foreach (['thumb', 'mobile', 'medium'] as $size) {
            $src = $this->mediaImageExtension->imageUrl($media, $size);
            $dimensions = $this->mediaImageExtension->imageDimensions($media, $size);
            if ($src === null || $dimensions === null) {
                continue;
            }

            $width = $dimensions['width'];
            if (isset($seenWidths[$width])) {
                continue;
            }

            $seenWidths[$width] = true;
            $entries[] = sprintf('%s %dw', $src, $width);
        }

        if ($entries === []) {
            $src = $this->mediaImageExtension->imageUrl($media, 'large');
            $dimensions = $this->mediaImageExtension->imageDimensions($media, 'large');
            if ($src !== null && $dimensions !== null) {
                $entries[] = sprintf('%s %dw', $src, $dimensions['width']);
            }
        }

        return $entries === [] ? null : implode(', ', $entries);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
