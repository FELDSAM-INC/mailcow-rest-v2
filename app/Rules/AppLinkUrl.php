<?php

namespace App\Rules;

/**
 * Feature 013 (FR-026, research R7): an app link address, either an absolute `http` or `https` URL
 * with a host, or a path on the mailcow host that starts with a single `/`, without whitespace or
 * control characters, at most 2,048 characters. Scheme-relative addresses (`//host`, `/\host`) and
 * every other scheme (`javascript:`, `data:`, `mailto:`) are `invalidFormat` with
 * `format: appLinkUrl`. The placeholder `%u` may appear anywhere.
 */
class AppLinkUrl extends CodedRule
{
    public const MAX = 2048;

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        if (mb_strlen($value) > self::MAX) {
            return $this->violation('tooLong', ['max' => self::MAX]);
        }

        return self::isValid($value) ? null : $this->violation('invalidFormat', ['format' => 'appLinkUrl']);
    }

    public static function isValid(string $value): bool
    {
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//') && ! str_starts_with($value, '/\\');
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && preg_match('#^https?://#i', $value) === 1;
    }
}
