<?php

namespace Tests\Unit\Http\Middleware;

use App\Auth\Role;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * T034 (FR-014, R8): a restricted session may only use operations marked for it.
 */
class EnforcePendingActionsTest extends TestCase
{
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api.v2', 'restv2.auth:session', 'restv2.pending'])
            ->get('/api/v2/__testing/pending/enforce', fn () => response()->noContent());
        Route::middleware(['api.v2', 'restv2.auth:session', 'restv2.pending:allow'])
            ->get('/api/v2/__testing/pending/allow', fn () => response()->noContent());
        Route::middleware(['api.v2', 'restv2.auth:session', 'restv2.pending:secondFactorSetup'])
            ->get('/api/v2/__testing/pending/second-factor-setup', fn () => response()->noContent());
        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.pending'])
            ->get('/api/v2/__testing/pending/key', fn () => response()->noContent());
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function signInMailbox(array $attributes): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', $attributes);
        $this->signInAs('bob@example.org', Role::MailboxUser);
    }

    public function test_a_restricted_session_is_refused_on_unmarked_routes(): void
    {
        $this->signInMailbox(['force_pw_update' => '1']);

        $this->getJson('/api/v2/__testing/pending/enforce')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required')
            ->assertJsonPath('pendingActions', ['passwordChange']);
    }

    public function test_an_unrestricted_session_passes(): void
    {
        $this->signInMailbox([]);

        $this->getJson('/api/v2/__testing/pending/enforce')->assertNoContent();
    }

    public function test_allow_passes_a_restricted_session(): void
    {
        $this->signInMailbox(['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->getJson('/api/v2/__testing/pending/allow')->assertNoContent();
    }

    public function test_second_factor_setup_passes_only_when_it_is_the_sole_pending_action(): void
    {
        $this->signInMailbox(['force_tfa' => '1']);
        $this->getJson('/api/v2/__testing/pending/second-factor-setup')->assertNoContent();
    }

    public function test_second_factor_setup_refuses_a_pending_password_change(): void
    {
        $this->signInMailbox(['force_tfa' => '1', 'force_pw_update' => '1']);

        $this->getJson('/api/v2/__testing/pending/second-factor-setup')
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required')
            ->assertJsonPath('pendingActions', ['passwordChange', 'secondFactorSetup']);
    }

    public function test_a_key_principal_is_never_restricted(): void
    {
        $this->mailcow()->admin('admin', 'x', attributes: ['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->getJson('/api/v2/__testing/pending/key', $this->keyHeader($this->apiKey('admin')))->assertNoContent();
    }
}
