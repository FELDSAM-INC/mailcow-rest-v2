<?php

namespace Tests\Unit\Logging;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T033 (R24): one JSON line per event with the request context, secrets removed, and the W3C
 * trace ID as the request ID.
 */
class StructuredLoggingTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('framework/testing/log-'.Str::random(8).'.json');
        File::ensureDirectoryExists(dirname($this->logFile));
        config(['logging.channels.stderr_json.handler_with.stream' => $this->logFile]);
        Log::forgetChannel('stderr_json');

        Route::middleware('api.v2')->get('/api/v2/__testing/log', function () {
            Log::info('probe', [
                'password' => 'hunter2',
                'code' => '123456',
                'otp' => 'cccccbtljhtl',
                'secret' => 'JBSWY3DPEHPK3PXP',
                'credential' => 'raw',
                'X-API-Key' => 'mcv2_secret',
                'nested' => ['token' => 'abc', 'kept' => 1],
                'kept' => 'yes',
            ]);

            return response()->noContent();
        })->name('testing.log');
    }

    protected function tearDown(): void
    {
        File::delete($this->logFile);
        Log::forgetChannel('stderr_json');

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function probeLine(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile))));
        $probes = array_values(array_filter(array_map(fn (string $l): array => json_decode($l, true) ?? [], $lines), fn (array $l): bool => ($l['message'] ?? null) === 'probe'));

        $this->assertCount(1, $probes, 'exactly one JSON line per log call');

        return $probes[0];
    }

    public function test_a_log_line_carries_the_request_context(): void
    {
        $this->getJson('/api/v2/__testing/log')->assertNoContent();

        $line = $this->probeLine();

        foreach (['request_id', 'route', 'method', 'principal', 'client_ip'] as $key) {
            $this->assertArrayHasKey($key, $line['extra'], "missing {$key}");
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $line['extra']['request_id']);
        $this->assertSame('GET', $line['extra']['method']);
        $this->assertSame('api/v2/__testing/log', $line['extra']['route']);
        $this->assertSame('127.0.0.1', $line['extra']['client_ip']);
        $this->assertNull($line['extra']['principal']);
    }

    public function test_secrets_are_removed_from_the_context(): void
    {
        $this->getJson('/api/v2/__testing/log')->assertNoContent();

        $context = $this->probeLine()['context'];

        foreach (['password', 'code', 'otp', 'secret', 'credential', 'X-API-Key'] as $key) {
            $this->assertArrayNotHasKey($key, $context);
        }
        $this->assertSame(['kept' => 1], $context['nested']);
        $this->assertSame('yes', $context['kept']);
        $this->assertStringNotContainsString('hunter2', (string) file_get_contents($this->logFile));
    }

    public function test_a_valid_traceparent_gives_the_request_id(): void
    {
        $this->getJson('/api/v2/__testing/log', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertNoContent();

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $this->probeLine()['extra']['request_id']);
    }

    public function test_an_invalid_traceparent_gives_a_new_request_id(): void
    {
        $this->getJson('/api/v2/__testing/log', ['traceparent' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01'])->assertNoContent();

        $requestId = $this->probeLine()['extra']['request_id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $requestId);
        $this->assertNotSame('00000000000000000000000000000000', $requestId);
    }
}
