<?php

namespace Tests\Feature\SelfService;

use App\Auth\Role;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * T011 (feature 005 research R5): the pending-action mode `includes,<action>`, which lets a
 * restricted session resolve either forced action in any order, and the read-only marker that lets
 * a read-only key use a POST that only reads (Sieve validation).
 */
class PipelineExtensionsTest extends TestCase
{
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api.v2', 'restv2.auth:session', 'restv2.pending:includes,passwordChange'])
            ->get('/api/v2/__testing/pending/includes-password', fn () => response()->noContent());
        Route::middleware(['api.v2', 'restv2.auth:session', 'restv2.pending:includes,secondFactorSetup'])
            ->get('/api/v2/__testing/pending/includes-second-factor', fn () => response()->noContent());
        Route::middleware(['api.v2', 'restv2.auth:authenticated'])
            ->post('/api/v2/__testing/read-only-post', fn () => response()->noContent())
            ->defaults('restv2.readOnly', true);
        Route::middleware(['api.v2', 'restv2.auth:authenticated'])
            ->post('/api/v2/__testing/changing-post', fn () => response()->noContent());
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function signInMailbox(array $attributes): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', $attributes);
        $this->signInAs('bob@example.org', Role::MailboxUser);
    }

    public function test_includes_password_change_passes_a_session_restricted_by_it_alone(): void
    {
        $this->signInMailbox(['force_pw_update' => '1']);

        $this->getJson('/api/v2/__testing/pending/includes-password')->assertNoContent();
    }

    public function test_includes_password_change_passes_a_session_restricted_by_both_actions(): void
    {
        $this->signInMailbox(['force_pw_update' => '1', 'force_tfa' => '1']);

        $this->getJson('/api/v2/__testing/pending/includes-password')->assertNoContent();
        $this->getJson('/api/v2/__testing/pending/includes-second-factor')->assertNoContent();
    }

    public function test_includes_password_change_refuses_a_session_restricted_only_by_second_factor_setup(): void
    {
        $this->signInMailbox(['force_tfa' => '1']);

        $this->getJson('/api/v2/__testing/pending/includes-password')
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required')
            ->assertJsonPath('pendingActions', ['secondFactorSetup']);
        $this->getJson('/api/v2/__testing/pending/includes-second-factor')->assertNoContent();
    }

    public function test_includes_second_factor_setup_refuses_a_session_restricted_only_by_a_password_change(): void
    {
        $this->signInMailbox(['force_pw_update' => '1']);

        $this->getJson('/api/v2/__testing/pending/includes-second-factor')
            ->assertStatus(403)
            ->assertJsonPath('pendingActions', ['passwordChange']);
    }

    public function test_an_unrestricted_session_passes_both_modes(): void
    {
        $this->signInMailbox([]);

        $this->getJson('/api/v2/__testing/pending/includes-password')->assertNoContent();
        $this->getJson('/api/v2/__testing/pending/includes-second-factor')->assertNoContent();
    }

    public function test_a_read_only_key_may_use_a_post_marked_as_read_only(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');

        $this->postJson('/api/v2/__testing/read-only-post', [], $this->keyHeader($key))->assertNoContent();
        $this->postJson('/api/v2/__testing/changing-post', [], $this->keyHeader($key))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }
}
