<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MediaVisualizeButtonContrastTest extends TestCase
{
    public function testMediaVisualizeButtonStatesKeepNormalTextAccessible(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/assets/styles/public-detail-gallery.css');
        self::assertIsString($css);

        $states = [
            'normal' => '.media-visualize-btn',
            'hover' => '.media-visualize-btn:hover',
            'focus visible' => '.media-visualize-btn:focus-visible',
            'active' => '.media-visualize-btn:active',
        ];
        $textColor = $this->cssColor($css, '.media-visualize-btn', 'color');

        foreach ($states as $label => $selector) {
            self::assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio($textColor, $this->cssColor($css, $selector, 'background')),
                sprintf('The media visualise button %s state must meet WCAG AA contrast for normal text.', $label),
            );
        }
    }

    private function cssColor(string $css, string $selector, string $property): string
    {
        $selectorPattern = preg_quote($selector, '/');
        $propertyPattern = preg_quote($property, '/');
        $rulePattern = '/(^|})\s*'.$selectorPattern.'\s*\{(?P<body>[^}]*)}/m';

        self::assertMatchesRegularExpression($rulePattern, $css, sprintf('Missing CSS selector %s.', $selector));
        preg_match($rulePattern, $css, $ruleMatches);

        self::assertArrayHasKey('body', $ruleMatches);
        self::assertMatchesRegularExpression(
            '/'.$propertyPattern.':\s*(?P<color>#[0-9a-fA-F]{3,6})\s*;/',
            $ruleMatches['body'],
            sprintf('Missing %s color on %s.', $property, $selector),
        );
        preg_match('/'.$propertyPattern.':\s*(?P<color>#[0-9a-fA-F]{3,6})\s*;/', $ruleMatches['body'], $colorMatches);

        return strtolower($this->normalizeHexColor($colorMatches['color']));
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = $this->relativeLuminance($foreground);
        $backgroundLuminance = $this->relativeLuminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        return 0.2126 * $this->linearRgb($red)
            + 0.7152 * $this->linearRgb($green)
            + 0.0722 * $this->linearRgb($blue);
    }

    private function linearRgb(int $value): float
    {
        $channel = $value / 255;

        if ($channel <= 0.03928) {
            return $channel / 12.92;
        }

        return (($channel + 0.055) / 1.055) ** 2.4;
    }

    private function normalizeHexColor(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (3 === strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }
}
