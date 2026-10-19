<?php

namespace App\Support\Runtime;

/**
 * Raises PHP's execution time limit for a long operation (feature 011's dockerapi actions:
 * DOCKER_TIMEOUT plus a margin), the way feature 009's SMTP probe does: only a finite limit
 * (php-fpm) is set; an unlimited one (the console, the test suite) stays unlimited, because
 * set_time_limit() applies to the rest of the process and would otherwise end an unrelated later
 * test after the given seconds.
 */
final class TimeLimit
{
    public static function raise(int $seconds): void
    {
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit($seconds);
        }
    }
}
