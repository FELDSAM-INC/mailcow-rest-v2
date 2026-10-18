<?php

namespace Tests\Feature\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * T101 (constitution runtime, R24): liveness checks nothing; readiness checks the database, the
 * mailcow schema version, Redis and this API's migrations. Both ignore `X-API-Key`.
 */
class HealthTest extends TestCase
{
    /**
     * The transaction of every test stays on the real connection while the default connection
     * is broken.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    private function breakDatabase(): void
    {
        config(['database.connections.broken' => array_merge((array) config('database.connections.mysql'), ['host' => 'database.invalid'])]);
        config(['database.default' => 'broken']);
    }

    private function breakRedis(): void
    {
        // The Redis manager keeps the configuration it was built with: rebuild it.
        config(['database.redis.restv2.port' => 1, 'database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
    }

    public function test_liveness_checks_no_dependency(): void
    {
        $this->breakDatabase();
        $this->breakRedis();

        $this->getJson('/api/v2/health/live')->assertOk()->assertExactJson(['data' => ['status' => 'ok']]);
    }

    public function test_readiness_reports_every_check(): void
    {
        $response = $this->getJson('/api/v2/health/ready')->assertOk();

        $response->assertExactJson(['data' => [
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'schema' => 'ok', 'redis' => 'ok', 'migrations' => 'ok'],
        ]]);
        $this->assertSame([], $response->headers->getCookies(), 'no session is started');
    }

    public function test_an_unsupported_schema_version_is_not_ready(): void
    {
        DB::table('versions')->where('application', 'db_schema')->update(['version' => '01012000_0000']);

        $this->getJson('/api/v2/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/service-unavailable')
            ->assertJsonPath('checks', ['database' => 'ok', 'schema' => 'failed', 'redis' => 'ok', 'migrations' => 'ok']);
    }

    public function test_a_pending_migration_is_not_ready(): void
    {
        $directory = storage_path('framework/testing/migrations-'.bin2hex(random_bytes(4)));
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/2099_01_01_000000_create_restv2_pending_table.php', "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };\n");
        $this->app['migrator']->path($directory);

        try {
            $this->getJson('/api/v2/health/ready')
                ->assertStatus(503)
                ->assertJsonPath('checks.migrations', 'failed')
                ->assertJsonPath('checks.database', 'ok');
        } finally {
            unlink($directory.'/2099_01_01_000000_create_restv2_pending_table.php');
            rmdir($directory);
        }
    }

    public function test_an_unreachable_redis_is_not_ready(): void
    {
        $this->breakRedis();

        $this->getJson('/api/v2/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks', ['database' => 'ok', 'schema' => 'ok', 'redis' => 'failed', 'migrations' => 'ok']);
    }

    public function test_an_unreachable_database_is_not_ready(): void
    {
        $this->breakDatabase();

        $this->getJson('/api/v2/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks', ['database' => 'failed', 'schema' => 'failed', 'redis' => 'ok', 'migrations' => 'failed']);
    }

    public function test_an_api_key_is_ignored(): void
    {
        $channel = ChannelSpy::fail2ban();

        $this->getJson('/api/v2/health/live', ['X-API-Key' => 'bogus'])->assertOk();
        $this->getJson('/api/v2/health/ready', ['X-API-Key' => 'bogus'])->assertOk();

        $channel->assertNothingPublished();
    }
}
