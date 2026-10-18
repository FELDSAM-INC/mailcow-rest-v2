<?php

namespace Tests\Feature\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * T101 (R24): `restv2:health --live` and `--ready`, the container health check (php-fpm speaks
 * only FastCGI), exit 0 when healthy and 1 when not.
 */
class HealthCommandTest extends TestCase
{
    public function test_liveness_and_readiness_when_healthy(): void
    {
        $this->artisan('restv2:health', ['--live' => true])->assertExitCode(0);
        $this->artisan('restv2:health', ['--ready' => true])->expectsOutputToContain('ready')->assertExitCode(0);
        // Readiness is the default.
        $this->artisan('restv2:health')->assertExitCode(0);
    }

    public function test_readiness_fails_with_the_failed_checks(): void
    {
        DB::table('versions')->where('application', 'db_schema')->update(['version' => '01012000_0000']);

        $this->artisan('restv2:health', ['--ready' => true])
            ->expectsOutputToContain('schema: failed')
            ->assertExitCode(1);
    }

    public function test_liveness_does_not_check_redis(): void
    {
        config(['database.redis.restv2.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->artisan('restv2:health', ['--live' => true])->assertExitCode(0);
        $this->artisan('restv2:health', ['--ready' => true])->expectsOutputToContain('redis: failed')->assertExitCode(1);
    }
}
