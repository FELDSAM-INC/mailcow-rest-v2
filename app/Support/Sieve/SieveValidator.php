<?php

namespace App\Support\Sieve;

use Exception;
use Sieve\SieveParser;

/**
 * Sieve validation exactly as legacy applies it (feature 005 FR-049, research R13): legacy's own
 * parser (`resources/legacy/sieve/`, copied unmodified from the upstream tag and loaded through
 * Composer's classmap) parses the script, and a refusal carries the parser's exception message.
 *
 * The parser predates PHP 8.4: compiling it raises a compile warning (`continue` targeting a
 * switch) and parsing raises dynamic-property deprecations. Both come from code this project must
 * not change, so they are silenced while the parser runs, and only for its files; anything else
 * reaches the previous error handler.
 */
final class SieveValidator
{
    /** The legacy parser's message for an invalid script, or null for a valid one. */
    public function error(string $script): ?string
    {
        return self::silenced(function () use ($script): ?string {
            try {
                (new SieveParser)->parse($script);

                return null;
            } catch (Exception $e) {
                // legacy catches Exception and shows its message (mailbox('add', 'filter')).
                return $e->getMessage();
            }
        });
    }

    /**
     * The params of a `sieveSyntax` violation: the message and, when the message names it, the
     * line (`line 3: …`).
     *
     * @return array{message: string, line?: int}
     */
    public static function violationParams(string $message): array
    {
        return preg_match('/^line (\d+):/', $message, $matches) === 1
            ? ['message' => $message, 'line' => (int) $matches[1]]
            : ['message' => $message];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $code
     * @return T
     */
    private static function silenced(callable $code): mixed
    {
        $directory = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'legacy'.DIRECTORY_SEPARATOR.'sieve';
        // A compile warning cannot reach an error handler; it is left out of the reported levels
        // while the parser's classes are loaded.
        $reporting = error_reporting(error_reporting() & ~E_COMPILE_WARNING);
        $previous = null;
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use ($directory, &$previous): bool {
            if (str_starts_with($file, $directory.DIRECTORY_SEPARATOR) && in_array($level, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_WARNING], true)) {
                return true;
            }

            return is_callable($previous) ? (bool) $previous($level, $message, $file, $line) : false;
        });

        try {
            return $code();
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }
}
