<?php

namespace App\Support\Rspamd;

/**
 * The line check of the global Rspamd regex maps (feature 013 research R13, FR-057), the rule the
 * legacy page applies in the browser, on the server: every line (a trailing `\r` removed) is empty,
 * a comment starting with `#`, or `/pattern/` followed by at most one of the flags `i`, `m`, `s`;
 * the pattern contains no further `/` (legacy's `split('/')` refuses such lines) and compiles with
 * PCRE, the library Rspamd uses for these maps. Reasons: `notSlashDelimited`, `tooManyFlags`,
 * `innerSlash`, `doesNotCompile`. The first failing line is reported, 1-based.
 */
final class RegexMapValidator
{
    /**
     * @return array{line: int, reason: string}|null
     */
    public function check(string $content): ?array
    {
        foreach (explode("\n", $content) as $index => $line) {
            $reason = self::reason(rtrim($line, "\r"));

            if ($reason !== null) {
                return ['line' => $index + 1, 'reason' => $reason];
            }
        }

        return null;
    }

    private static function reason(string $line): ?string
    {
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        $last = strrpos($line, '/');

        if (! str_starts_with($line, '/') || $last === false || $last === 0) {
            return 'notSlashDelimited';
        }

        $flags = substr($line, $last + 1);

        if (strlen($flags) > 1 && ctype_alpha($flags)) {
            return 'tooManyFlags';
        }

        if ($flags !== '' && ! in_array($flags, ['i', 'm', 's'], true)) {
            return 'notSlashDelimited';
        }

        $pattern = substr($line, 1, $last - 1);

        if (str_contains($pattern, '/')) {
            return 'innerSlash';
        }

        return @preg_match("\x01{$pattern}\x01{$flags}", '') === false ? 'doesNotCompile' : null;
    }
}
