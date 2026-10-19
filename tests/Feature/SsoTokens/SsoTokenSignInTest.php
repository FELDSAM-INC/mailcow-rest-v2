<?php

namespace Tests\Feature\SsoTokens;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * Feature 007 T079 (AS US5-2, US5-4, US5-6, FR-060 to FR-063, FR-036, research R20): a token signs
 * its domain administrator in once, within 30 seconds, without a second factor; failures are
 * generic, audited and throttled, never reported to fail2ban.
 */
class SsoTokenSignInTest extends TestCase
{
    use AccountTestHelpers;

    private const TOKEN = '3FA94C-0B12DE-77A0C1-E5D2B9-41C08F';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->mailboxWithDomain('bob@a.example');
    }

    private function signInWith(string $token): TestResponse
    {
        return $this->postJson('/api/v2/session/sso-token', ['token' => $token], $this->csrf());
    }

    public function test_a_token_signs_the_domain_administrator_in_without_a_second_factor(): void
    {
        $this->mailcow()->tfaTotp('reseller1', 'JBSWY3DPEHPK3PXP', 'Phone', 1);
        $this->mailcow()->daSso('reseller1', self::TOKEN);
        $this->csrfToken();
        $cookie = $this->sessionCookie();

        $this->signInWith(self::TOKEN)->assertCreated()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.username', 'reseller1')
            ->assertJsonPath('data.role', 'domainAdmin')
            ->assertJsonPath('data.authMethod', 'ssoToken');

        $this->assertNotSame($cookie, $this->sessionCookie());
        $this->assertFalse(DB::table('da_sso')->exists());
        $this->assertSame(1, (int) DB::table('tfa')->where('username', 'reseller1')->value('active'));
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.authentication.method', 'ssoToken');
        $record = $this->assertAudit('signIn', 'success', 'session', null, ['requestedRole' => 'domainAdmin', 'restricted' => false]);
        $this->assertSame(['reseller1', 'ssoToken'], [$record->actor_username, $record->auth_method]);
    }

    public function test_a_token_works_once_and_in_any_letter_case(): void
    {
        $this->mailcow()->daSso('reseller1', self::TOKEN);

        $this->signInWith('  '.strtolower(self::TOKEN).' ')->assertCreated();
        $this->forgetCookies();
        $this->signInWith(self::TOKEN)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
    }

    public function test_invalid_tokens_fail_with_the_generic_error(): void
    {
        $channel = ChannelSpy::fail2ban();
        $this->mailcow()->domainAdmin('inactive', ['a.example'], active: 0);
        $this->mailcow()->domainAdmin('later', ['a.example'], created: DB::selectOne('SELECT NOW() + INTERVAL 1 HOUR AS t')->t);
        $this->mailcow()->daSso('reseller1', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', createdSecondsAgo: 31);
        $this->mailcow()->daSso('inactive', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB');
        $this->mailcow()->daSso('gone', 'CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC');
        $this->mailcow()->daSso('later', 'DDDDDD-DDDDDD-DDDDDD-DDDDDD-DDDDDD');
        $this->mailcow()->daSso('alice', 'EEEEEE-EEEEEE-EEEEEE-EEEEEE-EEEEEE');

        foreach (['AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB', 'CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC', 'DDDDDD-DDDDDD-DDDDDD-DDDDDD-DDDDDD', 'EEEEEE-EEEEEE-EEEEEE-EEEEEE-EEEEEE', '123456-ABCDEF-123456-ABCDEF-12345G'] as $token) {
            $this->signInWith($token)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        }

        $this->assertSame(6, DB::table('restv2_audit_events')->where('event_type', 'signIn')->where('outcome', 'failure')->where('auth_method', 'ssoToken')->count());
        $channel->assertNothingPublished();
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_a_malformed_token_fails_without_a_lookup(): void
    {
        DB::enableQueryLog();

        $this->signInWith('not-a-token')->assertUnauthorized();

        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'da_sso'))));
    }

    public function test_a_forced_password_change_restricts_the_session(): void
    {
        DB::table('admin')->where('username', 'reseller1')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->mailcow()->daSso('reseller1', self::TOKEN);

        $this->signInWith(self::TOKEN)->assertCreated()->assertJsonPath('data.state', 'restricted')->assertJsonPath('data.pendingActions', ['passwordChange']);
    }

    public function test_an_existing_session_is_replaced_also_while_impersonating(): void
    {
        $this->mailcow()->daSso('reseller1', self::TOKEN);
        $old = $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();

        $this->signInWith(self::TOKEN)->assertCreated();

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'reseller1');
        $this->assertArrayNotHasKey('impersonation', $this->getJson('/api/v2/me')->json('data'));
        $this->assertAudit('impersonationEnd', 'success', 'impersonation', 'bob@a.example', ['endedBy' => 'sessionReplaced'], ['bob@a.example', 'mailboxUser']);
        $this->useSessionCookie($old);
        $this->getJson('/api/v2/session')->assertJsonPath('data.state', 'anonymous');
    }

    public function test_failures_are_throttled_per_address(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->signInWith('AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAA'.dechex($attempt % 16))->assertUnauthorized();
        }

        $this->mailcow()->daSso('reseller1', self::TOKEN);
        $this->signInWith(self::TOKEN)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('type', '/api/v2/problems/too-many-requests');
        $this->assertTrue(DB::table('da_sso')->exists());
    }

    public function test_a_billing_panel_signs_a_new_reseller_in_within_a_minute(): void
    {
        $started = microtime(true);
        $key = $this->keyHeader($this->apiKey('alice'));

        $this->postJson('/api/v2/domain-admins', ['username' => 'reseller2', 'password' => 'Passw0rd!x', 'domains' => ['a.example']], $key)->assertCreated();
        $token = (string) $this->postEmpty('/api/v2/domain-admins/reseller2/sso-token', $key)->assertOk()->json('data.token');
        $this->signInWith($token)->assertCreated()->assertJsonPath('data.username', 'reseller2');

        $this->assertLessThan(5, microtime(true) - $started);
    }
}
