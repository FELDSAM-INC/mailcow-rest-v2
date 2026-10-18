<?php

namespace Tests\Feature\System;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * T024 (FR-049, FR-055): every error is an RFC 9457 problem, unknown paths and methods included,
 * and errors are never localised.
 */
class ProblemRenderingTest extends TestCase
{
    public function test_an_unknown_path_is_a_404_problem(): void
    {
        $response = $this->getJson('/api/v2/no-such-resource');

        $response->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('title', 'Not Found');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $response->json('requestId'));
    }

    public function test_an_unsupported_method_is_a_405_problem_with_allow(): void
    {
        $response = $this->putJson('/api/v2/csrf-token');

        $response->assertStatus(405)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('status', 405);
        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    }

    public function test_an_unexpected_exception_is_a_500_problem_without_internals(): void
    {
        Route::middleware('api.v2')->get('/api/v2/__testing/boom', function (): never {
            throw new RuntimeException('database password is hunter2');
        });

        $response = $this->getJson('/api/v2/__testing/boom');

        $response->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('status', 500);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('trace', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $response->json('requestId'));
    }

    public function test_accept_language_changes_nothing(): void
    {
        $english = $this->getJson('/api/v2/no-such-resource')->json();
        $czech = $this->getJson('/api/v2/no-such-resource', ['Accept-Language' => 'cs'])->json();

        unset($english['requestId'], $czech['requestId']);
        $this->assertSame($english, $czech);
    }
}
