<?php

namespace App\Support\Config;

use Illuminate\Support\Env as LaravelEnv;

/**
 * Environment settings for the config files (docs/conventions.md "Configuration"): an empty or
 * whitespace-only value means unset, so the default applies.
 *
 * mailcow's compose override passes optional settings as `NAME=${NAME:-}`, which sets them to the
 * empty string, and Laravel's env() returns that string instead of the default: an empty
 * RESTV2_BULK_LIMIT became `(int) ''`, clamped to 1. Any other value, `0` included, is returned
 * with Laravel's conversions (`true`, `false`, `null`, quoted strings). A setting whose empty value
 * means something keeps env() and says so in a comment.
 */
final class Env
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = LaravelEnv::get($key);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return value($default);
        }

        return $value;
    }
}
