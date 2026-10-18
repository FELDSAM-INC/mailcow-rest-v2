<?php

namespace Tests\Feature\Containers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T109 (constitution VI, research R2): container environments hold mailcow's secrets;
 * after every container operation none of the planted values is in the shared cache, a response,
 * the log or the audit log.
 */
class ContainerEnvironmentLeakTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_no_environment_value_leaves_the_parser(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $this->dockerApi()->withStats('postfix-mailcow', MonitoringFixtures::stats('stats-postfix-3.json'));
        $this->asAdmin();
        $csrf = $this->csrf();
        $bodies = [];

        foreach (['/api/v2/host', '/api/v2/containers?limit=100', '/api/v2/containers/postfix-mailcow', '/api/v2/containers/postfix-mailcow/resource-usage', '/api/v2/containers/sogo-mailcow/resource-usage'] as $uri) {
            $bodies[] = (string) $this->getJson($uri)->getContent();
        }

        foreach (['start' => 'sogo-mailcow', 'stop' => 'clamd-mailcow', 'restart' => 'postfix-mailcow'] as $action => $service) {
            $bodies[] = (string) $this->postEmpty("/api/v2/containers/{$service}/{$action}", $csrf)->getContent();
        }

        $bodies[] = (string) $this->postEmpty('/api/v2/containers/mysql-mailcow/stop', $csrf)->getContent();
        $this->getJson('/api/v2/containers')->assertOk();

        $cache = [];
        foreach ((array) Redis::connection('restv2')->command('keys', ['monitoring:*']) as $key) {
            $cache[] = (string) Redis::connection('restv2')->command('get', [preg_replace('/^restv2:/', '', (string) $key)]);
        }

        $this->assertNotSame([], $cache, 'no snapshot was cached');
        $haystack = implode("\n", [...$bodies, ...$cache, ...$logged, (string) json_encode(DB::table('restv2_audit_events')->get())]);

        foreach (MonitoringFixtures::PLANTED_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }
}
