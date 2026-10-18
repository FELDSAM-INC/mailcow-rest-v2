<?php

namespace Tests\Feature\DnsDiagnostics;

use App\Auth\Role;
use Illuminate\Support\Facades\Redis;
use Tests\Support\DnsDiagnostics\DiagnosticsTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T059 (US3 AS8, FR-049, research R20): each caller (a user or an API key) may start 10
 * runs per minute; every result and every refusal carries `RateLimit-Limit`, `RateLimit-Remaining`
 * and `RateLimit-Reset`, and the eleventh run is refused before any lookup.
 */
class DnsDiagnosticsRateLimitTest extends TestCase
{
    use DiagnosticsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->server();
        $this->mailcow()->admin('admin', 'Passw0rd!');
    }

    public function test_ten_runs_per_minute_with_their_headers_then_too_many_requests(): void
    {
        $this->signInAs('admin', Role::Admin);

        for ($run = 1; $run <= 10; $run++) {
            $response = $this->diagnose('example.org')->assertOk()
                ->assertHeader('RateLimit-Limit', '10')
                ->assertHeader('RateLimit-Remaining', (string) (10 - $run));
            $reset = (int) $response->headers->get('RateLimit-Reset');
            $this->assertGreaterThanOrEqual(0, $reset);
            $this->assertLessThanOrEqual(60, $reset);
        }

        $lookups = $this->dns()->lookupCount();
        $refused = $this->diagnose('example.org')->assertStatus(429)
            ->assertJsonPath('type', '/api/v2/problems/too-many-requests')
            ->assertHeader('RateLimit-Limit', '10')
            ->assertHeader('RateLimit-Remaining', '0');

        $retryAfter = (int) $refused->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $refused->assertJsonPath('retryAfter', $retryAfter);
        $this->assertSame($lookups, $this->dns()->lookupCount(), 'The refused run made lookups.');
        $this->assertSame(0, $this->auditTotal());
    }

    public function test_a_name_that_is_not_found_does_not_count(): void
    {
        $this->signInAs('admin', Role::Admin);

        $this->diagnose('nothing.example')->assertNotFound();
        $this->diagnose('example.org')->assertOk()->assertHeader('RateLimit-Remaining', '9');
    }

    public function test_a_session_and_an_api_key_of_the_same_owner_count_separately_and_the_window_ends(): void
    {
        $key = $this->apiKey('admin');
        $this->signInAs('admin', Role::Admin);

        for ($run = 1; $run <= 10; $run++) {
            $this->diagnose('example.org')->assertOk();
        }

        $this->diagnose('example.org')->assertStatus(429);
        $this->diagnose('example.org', $this->keyHeader($key))->assertOk()->assertHeader('RateLimit-Remaining', '9');

        $this->clock()->advance(61);
        $this->diagnose('example.org')->assertOk()->assertHeader('RateLimit-Remaining', '9');
    }

    public function test_the_counter_lives_under_the_hash_of_the_caller(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->diagnose('example.org')->assertOk();

        $this->assertTrue((bool) Redis::connection('restv2')->exists('dns-diagnostics:'.hash('sha256', 'user:admin')));
    }

    public function test_the_limit_is_a_setting(): void
    {
        config(['restv2.dns.runs_per_minute' => 2]);
        $this->signInAs('admin', Role::Admin);

        $this->diagnose('example.org')->assertOk()->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1');
        $this->diagnose('example.org')->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $this->diagnose('example.org')->assertStatus(429);
    }
}
