<?php

namespace Tests\Unit\Http\Middleware;

use App\Auth\Role;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Http\MarkAsApiKeyRequest;
use Tests\TestCase;

/**
 * T034 (R8): the four authentication modes of `restv2.auth:<mode>`.
 */
class RequireAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['public', 'browser', 'session', 'authenticated'] as $mode) {
            Route::middleware(['api.v2', "restv2.auth:{$mode}", 'restv2.pending:allow'])
                ->get("/api/v2/__testing/auth/{$mode}", fn () => response()->noContent());
            Route::middleware(['api.v2', MarkAsApiKeyRequest::class, "restv2.auth:{$mode}", 'restv2.pending:allow'])
                ->get("/api/v2/__testing/key-auth/{$mode}", fn () => response()->noContent());
        }
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertProblem(TestResponse $response, int $status, string $type): void
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', "/api/v2/problems/{$type}");
    }

    public function test_session_and_authenticated_need_a_principal(): void
    {
        $this->assertProblem($this->getJson('/api/v2/__testing/auth/session'), 401, 'unauthenticated');
        $this->assertProblem($this->getJson('/api/v2/__testing/auth/authenticated'), 401, 'unauthenticated');
    }

    public function test_a_pending_sign_in_needs_its_second_factor(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInPending('admin', Role::Admin, [1]);

        $this->assertProblem($this->getJson('/api/v2/__testing/auth/session'), 401, 'second-factor-required');
        $this->assertProblem($this->getJson('/api/v2/__testing/auth/authenticated'), 401, 'second-factor-required');
        $this->getJson('/api/v2/__testing/auth/browser')->assertNoContent();
    }

    public function test_an_expired_pending_sign_in_is_anonymous(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInPending('admin', Role::Admin, [1]);
        $this->travelPastChallengeTtl();

        $this->assertProblem($this->getJson('/api/v2/__testing/auth/session'), 401, 'unauthenticated');
    }

    public function test_session_and_browser_refuse_api_keys(): void
    {
        $this->assertProblem($this->getJson('/api/v2/__testing/key-auth/session'), 403, 'session-required');
        $this->assertProblem($this->getJson('/api/v2/__testing/key-auth/browser'), 403, 'session-required');
    }

    public function test_browser_passes_without_a_session_and_public_passes_with_a_key(): void
    {
        $this->getJson('/api/v2/__testing/auth/browser')->assertNoContent();
        $this->getJson('/api/v2/__testing/auth/public')->assertNoContent();
        $this->getJson('/api/v2/__testing/key-auth/public')->assertNoContent();
    }

    public function test_authenticated_accepts_a_key_or_a_signed_in_session(): void
    {
        $this->getJson('/api/v2/__testing/key-auth/authenticated')->assertNoContent();

        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/__testing/auth/authenticated')->assertNoContent();
        $this->getJson('/api/v2/__testing/auth/session')->assertNoContent();
    }
}
