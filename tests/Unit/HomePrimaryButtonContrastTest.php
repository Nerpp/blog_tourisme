<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HomePrimaryButtonContrastTest extends TestCase
{
    public function testHomePrimaryButtonStatesKeepWhiteTextAccessible(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/assets/styles/home.css');
        self::assertIsString($css);

        foreach ([
            '.home-primary-btn',
            '.home-primary-btn:hover',
            '.home-primary-btn:focus-visible',
            '.home-primary-btn:active',
        ] as $selector) {
            self::assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio('#ffffff', $this->backgroundForSelector($css, $selector)),
                sprintf('%s must keep normal white text at WCAG AA contrast.', $selector),
            );
        }
    }

    private function backgroundForSelector(string $css, string $selector): string
    {
        $selectorPattern = preg_quote($selector, '/');
        $rulePattern = '/(^|})\s*'.$selectorPattern.'\s*\{(?P<body>[^}]*)}/m';

        self::assertMatchesRegularExpression($rulePattern, $css, sprintf('Missing CSS selector %s.', $selector));
        preg_match($rulePattern, $css, $ruleMatches);

        self::assertArrayHasKey('body', $ruleMatches);
        self::assertMatchesRegularExpression('/background:\s*(#[0-9a-fA-F]{6})\s*;/', $ruleMatches['body']);
        preg_match('/background:\s*(#[0-9a-fA-F]{6})\s*;/', $ruleMatches['body'], $backgroundMatches);

        return strtolower($backgroundMatches[1]);
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
}
