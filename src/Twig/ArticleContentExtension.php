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

        $rendered = preg_replace_callback(
            '/\[\[media:(\d+)\]\]/',
            function (array $matches) use ($linkedMedia, $article): string {
                $media = $linkedMedia[(int) $matches[1]] ?? null;
                if (!$media instanceof MediaAsset || $media->getMediaType() !== MediaType::Image) {
                    return '';
                }

                return $this->mediaFigureHtml($media, $article);
            },
            $content,
        ) ?? $content;

        return preg_replace(
            '/<p>\s*(<figure class="article-content-media">.*?<\/figure>)\s*<\/p>/s',
            '$1',
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

        return $mediaById;
    }

    private function mediaFigureHtml(MediaAsset $media, Article $article): string
    {
        $src = $this->mediaImageExtension->imageUrl($media, 'large')
            ?? $this->mediaImageExtension->imageUrl($media, 'medium')
            ?? $this->mediaImageExtension->imageUrl($media, 'thumb');

        if ($src === null) {
            return '';
        }

        $isStandardImage = $media->getImageType() === ImageType::Standard;
        $avifSrcset = $isStandardImage ? null : $this->mediaImageExtension->imageSrcset($media, 'avif');
        $webpSrcset = $this->mediaImageExtension->imageSrcset($media, 'webp');
        $fallbackSrcset = $isStandardImage ? null : $this->mediaImageExtension->imageSrcset($media, 'fallback');
        $imgSrcset = $isStandardImage ? $webpSrcset : $fallbackSrcset;
        $dimensions = $this->mediaImageExtension->imageDimensions($media, 'large')
            ?? $this->mediaImageExtension->imageDimensions($media, 'medium')
            ?? $this->mediaImageExtension->imageDimensions($media, 'thumb');
        $title = $this->mediaImageExtension->publicTitle($media, $article, $article->getTitle());
        $alt = $this->mediaImageExtension->publicAlt($media, $article, $title ?? $article->getTitle());

        $html = '<figure class="article-content-media"><picture>';
        if ($avifSrcset !== null) {
            $html .= sprintf(
                '<source type="image/avif" srcset="%s" sizes="(min-width: 900px) 760px, 100vw">',
                $this->escape($avifSrcset),
            );
        }

        if (!$isStandardImage && $webpSrcset !== null) {
            $html .= sprintf(
                '<source type="image/webp" srcset="%s" sizes="(min-width: 900px) 760px, 100vw">',
                $this->escape($webpSrcset),
            );
        }

        $html .= sprintf(
            '<img src="%s" alt="%s" loading="lazy" decoding="async"%s%s>',
            $this->escape($src),
            $this->escape($alt),
            $imgSrcset !== null ? sprintf(' srcset="%s" sizes="(min-width: 900px) 760px, 100vw"', $this->escape($imgSrcset)) : '',
            $dimensions !== null ? sprintf(' width="%d" height="%d"', $dimensions['width'], $dimensions['height']) : '',
        );
        $html .= '</picture>';

        if ($title !== null && $title !== '') {
            $html .= sprintf('<figcaption>%s</figcaption>', $this->escape($title));
        }

        return $html.'</figure>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
