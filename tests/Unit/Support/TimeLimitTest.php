<?php

namespace Tests\Unit\Support;

use App\Support\Runtime\TimeLimit;
use PHPUnit\Framework\TestCase;

/**
 * An unlimited execution time (the console, the test suite) stays unlimited when a long operation
 * raises the limit; a finite one (php-fpm) is raised. Feature 011's container actions left an
 * 80-second limit on the whole test process, which ended an unrelated later test.
 */
class TimeLimitTest extends TestCase
{
    public function test_an_unlimited_time_stays_unlimited_and_a_finite_one_is_raised(): void
    {
        $previous = ini_get('max_execution_time');

        try {
            set_time_limit(0);
            TimeLimit::raise(80);
            $this->assertSame('0', ini_get('max_execution_time'));

            set_time_limit(30);
            TimeLimit::raise(80);
            $this->assertSame('80', ini_get('max_execution_time'));
        } finally {
            set_time_limit((int) $previous);
        }
    }
}
