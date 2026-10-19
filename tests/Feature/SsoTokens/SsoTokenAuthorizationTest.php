<?php

namespace Tests\Feature\SsoTokens;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T081 (AS US5-7, FR-005): only administrators issue tokens, with a session or a
 * read-write key; anyone may sign in with a token, but only in a browser session.
 */
class SsoTokenAuthorizationTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->mailboxWithDomain('bob@a.example');
    }

    public function test_who_may_issue_a_token(): void
    {
        $path = '/api/v2/domain-admins/reseller1/sso-token';

        $this->postEmpty($path, $this->keyHeader($this->apiKey('reseller1')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->postEmpty($path, $this->keyHeader($this->apiKey('alice', 'readOnly')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->postEmpty($path, $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->signInAs('bob@a.example', Role::MailboxUser);
        $this->postEmpty($path, $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertSame(0, DB::table('da_sso')->count());

        $this->signInAs('alice', Role::Admin);
        $this->postEmpty($path, $this->csrf())->assertOk();

        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->postEmpty($path, $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }

    public function test_a_token_sign_in_needs_a_browser_session_and_the_anti_forgery_token(): void
    {
        $this->mailcow()->daSso('reseller1', '3FA94C-0B12DE-77A0C1-E5D2B9-41C08F');

        $this->postJson('/api/v2/session/sso-token', ['token' => '3FA94C-0B12DE-77A0C1-E5D2B9-41C08F'], $this->keyHeader($this->apiKey('alice')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->postJson('/api/v2/session/sso-token', ['token' => '3FA94C-0B12DE-77A0C1-E5D2B9-41C08F'])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->assertTrue(DB::table('da_sso')->exists());
    }
}
