<?php

namespace App\Rules;

use App\Support\Color\WcagContrast;

/**
 * Feature 013 (FR-030, research R5): a brand colour must reach 4.5:1 against its theme's reference
 * surface; otherwise `insufficientContrast` with the measured ratio (rounded down to two decimals),
 * the minimum and the surface.
 */
class BrandColorContrast extends CodedRule
{
    public function __construct(private readonly string $surface) {}

    protected function check(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            return null;
        }

        $ratio = WcagContrast::ratio(strtolower($value), $this->surface);

        return $ratio >= WcagContrast::MINIMUM
            ? null
            : $this->violation('insufficientContrast', ['ratio' => WcagContrast::roundedDown($ratio), 'minimum' => WcagContrast::MINIMUM, 'surface' => $this->surface]);
    }
}
