<?php

namespace App\Service\Article;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

final class ArticleContentSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED_TAGS = [
        'p' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'strong' => true,
        'em' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'blockquote' => true,
        'a' => true,
        'figure' => true,
        'figcaption' => true,
        'br' => true,
    ];

    /** @var array<string, true> */
    private const DROP_WITH_CONTENT = [
        'script' => true,
        'style' => true,
        'iframe' => true,
        'object' => true,
        'embed' => true,
        'svg' => true,
        'math' => true,
        'form' => true,
        'input' => true,
        'button' => true,
        'textarea' => true,
        'select' => true,
        'option' => true,
    ];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        if ($html === strip_tags($html)) {
            return $this->plainTextToHtml($html);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousUseErrors = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><article-content>'.$html.'</article-content>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $root = $document->getElementsByTagName('article-content')->item(0);
        if (!$root instanceof DOMElement) {
            return '';
        }

        return trim($this->sanitizeChildren($root));
    }

    private function sanitizeChildren(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $this->sanitizeNode($child);
        }

        return $html;
    }

    private function sanitizeNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (isset(self::DROP_WITH_CONTENT[$tag])) {
            return '';
        }

        $children = $this->sanitizeChildren($node);
        $tag = match ($tag) {
            'b' => 'strong',
            'i' => 'em',
            default => $tag,
        };

        if (!isset(self::ALLOWED_TAGS[$tag])) {
            return $children;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if ($tag === 'a') {
            $href = $this->safeHref($node->getAttribute('href'));
            if ($href === null || $children === '') {
                return $children;
            }

            $attributes = sprintf(' href="%s"', htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                $attributes .= ' rel="noopener noreferrer"';
            }

            return sprintf('<a%s>%s</a>', $attributes, $children);
        }

        if ($children === '' && $tag !== 'p') {
            return '';
        }

        return sprintf('<%s>%s</%s>', $tag, $children, $tag);
    }

    private function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }

        $normalized = strtolower(preg_replace('/\s+/', '', $href) ?? $href);
        if (
            str_starts_with($normalized, 'javascript:')
            || str_starts_with($normalized, 'data:')
            || str_starts_with($normalized, 'vbscript:')
        ) {
            return null;
        }

        if (
            str_starts_with($href, 'http://')
            || str_starts_with($href, 'https://')
            || str_starts_with($href, 'mailto:')
            || str_starts_with($href, 'tel:')
            || str_starts_with($href, '/')
            || str_starts_with($href, '#')
        ) {
            return $href;
        }

        return null;
    }

    private function plainTextToHtml(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
        $html = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $html[] = sprintf(
                '<p>%s</p>',
                preg_replace(
                    '/\R/',
                    '<br>',
                    htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ),
            );
        }

        return implode('', $html);
    }
}
