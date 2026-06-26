<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NavbarRegisterContrastTest extends TestCase
{
    public function testRegisterNavbarLinkBackgroundsKeepWhiteTextAccessible(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/assets/styles/navbar.css');
        self::assertIsString($css);

        $backgrounds = $this->backgroundsForSelector($css, '.nav-auth-link--register');
        $backgrounds = array_merge($backgrounds, $this->backgroundsForSelector($css, '.nav-auth-link--register:hover'));
        $backgrounds = array_merge($backgrounds, $this->backgroundsForSelector($css, '.nav-auth-link--register:focus-visible'));
        $backgrounds = array_merge($backgrounds, $this->backgroundsForSelector($css, '.nav-auth-link--register:active'));

        self::assertNotSame([], $backgrounds);

        foreach ($backgrounds as $background) {
            self::assertGreaterThanOrEqual(
                4.5,
                $this->contrastRatio('#ffffff', $background),
                sprintf('Navbar register link background %s must keep normal white text at WCAG AA contrast.', $background),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function backgroundsForSelector(string $css, string $selector): array
    {
        $selectorPattern = preg_quote($selector, '/');
        preg_match_all('/(^|})\s*'.$selectorPattern.'\s*\{(?P<body>[^}]*)}/m', $css, $ruleMatches);

        $backgrounds = [];
        foreach ($ruleMatches['body'] as $body) {
            if (preg_match('/background:\s*(#[0-9a-fA-F]{6})\s*;/', $body, $backgroundMatch)) {
                $backgrounds[] = strtolower($backgroundMatch[1]);
            }
        }

        return $backgrounds;
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
