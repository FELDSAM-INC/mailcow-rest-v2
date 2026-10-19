<?php

namespace Tests\Unit\Support;

use App\Support\Color\WcagContrast;
use PHPUnit\Framework\TestCase;

/**
 * Feature 013 T025 (research R5; FR-029 to FR-031, SC-004): WCAG 2.2 relative luminance and contrast
 * ratios, the 4.5:1 rule against the light (`#ffffff`) and dark (`#1d2939`) reference surfaces, the
 * effective colours per theme and their black or white foreground.
 */
class WcagContrastTest extends TestCase
{
    public function test_known_ratios(): void
    {
        $this->assertEqualsWithDelta(21.0, WcagContrast::ratio('#ffffff', '#000000'), 0.0001);
        $this->assertEqualsWithDelta(21.0, WcagContrast::ratio('#000000', '#FFFFFF'), 0.0001);
        $this->assertSame(6.70, WcagContrast::roundedDown(WcagContrast::ratio('#1d4ed8', '#ffffff')));
        $this->assertSame(2.19, WcagContrast::roundedDown(WcagContrast::ratio('#1d4ed8', '#1d2939')));
        $this->assertSame(1.4, WcagContrast::roundedDown(WcagContrast::ratio('#ffd700', '#ffffff')));
        $this->assertSame(1.0, WcagContrast::ratio('#777777', '#777777'));
    }

    public function test_the_boundary_is_exact_and_the_reported_ratio_rounded_down(): void
    {
        $found = null;

        for ($v = 0x60; $v <= 0x90 && $found === null; $v++) {
            for ($b = 0; $b <= 0xFF && $found === null; $b++) {
                $color = sprintf('#%02x%02x%02x', $v, $v, $b);
                $ratio = WcagContrast::ratio($color, WcagContrast::LIGHT_SURFACE);

                if ($ratio >= 4.49 && $ratio < 4.5) {
                    $found = [$color, $ratio];
                }
            }
        }

        $this->assertNotNull($found, 'A colour just below 4.5:1 exists.');
        [$color] = $found;
        $this->assertFalse(WcagContrast::passes($color, WcagContrast::LIGHT_SURFACE));
        $this->assertSame(4.49, WcagContrast::roundedDown(WcagContrast::ratio($color, WcagContrast::LIGHT_SURFACE)));
        $this->assertTrue(WcagContrast::passes('#767676', WcagContrast::LIGHT_SURFACE), '#767676 reaches 4.54:1 on white.');
    }

    public function test_the_foreground_is_the_more_contrasting_of_black_and_white(): void
    {
        $this->assertSame('#ffffff', WcagContrast::foreground('#1d4ed8'));
        $this->assertSame('#000000', WcagContrast::foreground('#ffd700'));
        $this->assertSame('#000000', WcagContrast::foreground('#ffffff'));
        $this->assertSame('#ffffff', WcagContrast::foreground('#000000'));
    }

    public function test_effective_colours_follow_fr_031(): void
    {
        $blue = '#1d4ed8';      // passes on white only
        $sky = '#93c5fd';       // passes on the dark surface only
        $lightAndDark = null;

        foreach (['#757575', '#6b7280', '#0f766e', '#7c7c7c'] as $candidate) {
            if (WcagContrast::passes($candidate, WcagContrast::LIGHT_SURFACE) && WcagContrast::passes($candidate, WcagContrast::DARK_SURFACE)) {
                $lightAndDark = $candidate;
            }
        }

        $this->assertSame(['light' => null, 'dark' => null], WcagContrast::effective(null, null));
        $this->assertSame(['light' => ['color' => $blue, 'foreground' => '#ffffff'], 'dark' => null], WcagContrast::effective($blue, null));
        $this->assertSame(['light' => null, 'dark' => ['color' => $sky, 'foreground' => '#000000']], WcagContrast::effective(null, $sky));
        $this->assertSame(['light' => ['color' => $blue, 'foreground' => '#ffffff'], 'dark' => ['color' => $sky, 'foreground' => '#000000']], WcagContrast::effective($blue, $sky));

        if ($lightAndDark !== null) {
            $effective = WcagContrast::effective($lightAndDark, null);
            $this->assertSame($lightAndDark, $effective['dark']['color'] ?? null, 'A light colour that passes the dark rule applies to the dark theme too.');
        }

    }

    public function test_every_accepted_colour_reaches_4_5_with_its_foreground(): void
    {
        mt_srand(13);

        for ($i = 0; $i < 1000; $i++) {
            $color = sprintf('#%06x', mt_rand(0, 0xFFFFFF));

            foreach ([WcagContrast::LIGHT_SURFACE, WcagContrast::DARK_SURFACE] as $surface) {
                if (! WcagContrast::passes($color, $surface)) {
                    continue;
                }

                $foreground = WcagContrast::foreground($color);
                $this->assertGreaterThanOrEqual(4.5, WcagContrast::ratio($color, $foreground), "{$color} with {$foreground}");
            }
        }
    }
}
