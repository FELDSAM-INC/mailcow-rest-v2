<?php

namespace App\Support\Color;

/** WCAG arithmetic for text colours. Brand fills are preserved with black or white foregrounds. */
final class WcagContrast
{
    public const LIGHT_SURFACE = '#ffffff';

    public const DARK_SURFACE = '#222932';

    public const MINIMUM = 4.5;

    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $linear = array_map(static function (int|float $value): float {
            $c = $value / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function roundedDown(float $ratio): float
    {
        return floor($ratio * 100) / 100;
    }

    public static function passes(string $color, string $surface): bool
    {
        return self::ratio($color, $surface) >= self::MINIMUM;
    }

    public static function foreground(string $color): string
    {
        return self::ratio($color, '#ffffff') > self::ratio($color, '#000000') ? '#ffffff' : '#000000';
    }

    /**
     * The colours in effect per theme (FR-031), each with its foreground.
     *
     * @return array{light: array{color: string, foreground: string}|null, dark: array{color: string, foreground: string}|null}
     */
    public static function effective(?string $light, ?string $dark): array
    {
        $entry = fn (string $color): array => ['color' => $color, 'foreground' => self::foreground($color)];
        $darkColor = $dark ?? $light;

        return [
            'light' => $light === null ? null : $entry($light),
            'dark' => $darkColor === null ? null : $entry($darkColor),
        ];
    }
}
