<?php

namespace Tests\Unit\Http\Middleware;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * T034 (R8): the four authentication modes of `restv2.auth:<mode>`.
 */
class RequireAuthTest extends TestCase
{
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['public', 'browser', 'session', 'authenticated'] as $mode) {
            Route::middleware(['api.v2', "restv2.auth:{$mode}", 'restv2.pending:allow'])
                ->get("/api/v2/__testing/auth/{$mode}", fn () => response()->noContent());
        }
    }

    /**
     * A request authenticated by an administrator's API key.
     *
     * @return TestResponse<Response>
     */
    private function withKey(string $mode): TestResponse
    {
        if (! DB::table('admin')->where('username', 'keyowner')->exists()) {
            $this->mailcow()->admin('keyowner', 'x');
        }

        return $this->getJson("/api/v2/__testing/auth/{$mode}", $this->keyHeader($this->apiKey('keyowner')));
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
        $this->assertProblem($this->withKey('session'), 403, 'session-required');
        $this->assertProblem($this->withKey('browser'), 403, 'session-required');
    }

    public function test_browser_passes_without_a_session_and_public_passes_with_a_key(): void
    {
        $this->getJson('/api/v2/__testing/auth/browser')->assertNoContent();
        $this->getJson('/api/v2/__testing/auth/public')->assertNoContent();
        $this->withKey('public')->assertNoContent();
    }

    public function test_authenticated_accepts_a_key_or_a_signed_in_session(): void
    {
        $this->withKey('authenticated')->assertNoContent();

        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/__testing/auth/authenticated')->assertNoContent();
        $this->getJson('/api/v2/__testing/auth/session')->assertNoContent();
    }
}
