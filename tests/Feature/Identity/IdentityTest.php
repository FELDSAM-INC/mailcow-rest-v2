<?php

namespace Tests\Feature\Identity;

use App\Auth\AuthMethod;
use App\Auth\PermissionFlag;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T076 (FR-013, FR-028, FR-029, AS-1, AS-2, AS-5): getIdentity describes the signed-in caller from
 * mailcow's current data on every request.
 */
class IdentityTest extends TestCase
{
    use AuthRequests;

    public function test_a_domain_admin_sees_the_domains_of_active_assignments(): void
    {
        $factory = $this->mailcow();
        $factory->ensureDomain('b.example');
        $factory->ensureDomain('a.example');
        $factory->ensureDomain('c.example');
        $factory->domainAdmin('reseller', ['b.example', 'a.example', 'c.example'], ['login_as' => 0, 'syncjobs' => 1, 'pushover' => 1]);
        DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'c.example')->update(['active' => 0]);
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->getJson('/api/v2/me')->assertOk();

        $response->assertJsonPath('data.username', 'reseller')
            ->assertJsonPath('data.role', 'domainAdmin')
            ->assertJsonPath('data.scope', ['type' => 'domains', 'domains' => ['a.example', 'b.example']])
            ->assertJsonPath('data.permissions.loginAs', false)
            ->assertJsonPath('data.permissions.syncjobs', true)
            ->assertJsonPath('data.permissions.pushover', true)
            // da_acl defaults of the remaining columns
            ->assertJsonPath('data.permissions.aliasDomains', false)
            ->assertJsonPath('data.permissions.bccMaps', true)
            // user_acl-only flags are granted to domain admins
            ->assertJsonPath('data.permissions.spamAlias', true)
            ->assertJsonPath('data.pendingActions', []);
        $this->assertCount(29, $response->json('data.permissions'));
        $this->assertArrayNotHasKey('impersonation', $response->json('data'));
        $this->assertArrayNotHasKey('webmailOnBehalfEnabled', $response->json('data'));
    }

    public function test_an_inactive_domain_that_is_still_assigned_stays_in_scope(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('off.example', 0);
        $this->mailcow()->domainAdmin('reseller', ['a.example', 'off.example']);
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson('/api/v2/me')->assertJsonPath('data.scope.domains', ['a.example', 'off.example']);
    }

    public function test_a_domain_admin_without_domains_has_an_empty_scope(): void
    {
        $this->mailcow()->domainAdmin('lonely', []);
        $this->signInAs('lonely', Role::DomainAdmin);

        $this->getJson('/api/v2/me')->assertJsonPath('data.scope', ['type' => 'domains', 'domains' => []]);
    }

    public function test_a_mailbox_user_sees_the_own_mailbox_and_user_acl_flags(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org', userAcl: ['syncjobs' => 1, 'pw_reset' => 0]);
        $this->signInAs('alice@example.org', Role::MailboxUser, AuthMethod::PasswordAndSecondFactor);

        $response = $this->getJson('/api/v2/me')->assertOk();

        $response->assertJsonPath('data.role', 'mailboxUser')
            ->assertJsonPath('data.scope', ['type' => 'mailbox', 'mailbox' => 'alice@example.org', 'domain' => 'example.org'])
            ->assertJsonPath('data.authentication', ['method' => 'passwordAndSecondFactor'])
            ->assertJsonPath('data.permissions.syncjobs', true)
            ->assertJsonPath('data.permissions.pwReset', false)
            ->assertJsonPath('data.permissions.sogoProfileReset', false)
            ->assertJsonPath('data.permissions.spamAlias', true)
            ->assertJsonPath('data.permissions.loginAs', false);
    }

    public function test_an_admin_has_every_domain_and_flag(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin, AuthMethod::Passkey);

        $response = $this->getJson('/api/v2/me')->assertOk();

        $response->assertJsonPath('data.scope', ['type' => 'allDomains'])
            ->assertJsonPath('data.authentication', ['method' => 'passkey']);
        foreach (PermissionFlag::cases() as $flag) {
            $this->assertTrue($response->json('data.permissions.'.$flag->value), $flag->value);
        }
    }

    public function test_the_authentication_method_is_the_sign_in_method(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');

        $this->passwordSignIn('admin')->assertCreated();
        $this->getJson('/api/v2/me')->assertJsonPath('data.authentication.method', 'password');
    }

    public function test_sessions_carry_the_preferences(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $response = $this->getJson('/api/v2/me')->assertOk();

        $response->assertJsonPath('data.preferences.language', 'cs-cz')
            ->assertJsonPath('data.preferences.theme', 'system');
        $this->assertIsString($response->json('data.preferences.etag'));
    }

    public function test_changes_in_mailcow_apply_to_the_next_request(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->mailcow()->domainAdmin('reseller', ['a.example', 'b.example']);
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/me')->assertJsonPath('data.permissions.loginAs', true)->assertJsonPath('data.scope.domains', ['a.example', 'b.example']);

        DB::table('da_acl')->where('username', 'reseller')->update(['login_as' => 0]);
        DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'b.example')->update(['active' => 0]);

        $this->getJson('/api/v2/me')
            ->assertJsonPath('data.permissions.loginAs', false)
            ->assertJsonPath('data.scope.domains', ['a.example']);
    }

    public function test_a_restricted_session_reads_its_pending_actions(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.pendingActions', ['passwordChange']);
    }

    public function test_a_deactivated_account_ends_the_session(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $sessionId = $this->signInAs('alice@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/me')->assertOk();

        DB::table('mailbox')->where('username', 'alice@example.org')->update(['active' => 0]);

        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
        $this->assertFalse((bool) Redis::connection('mailcow')->exists('restv2:session:'.$sessionId));
    }

    public function test_signing_in_leaves_every_key_outside_the_api_prefix_untouched(): void
    {
        $redis = Redis::connection('mailcow');
        $redis->set('PHPREDIS_SESSION:legacy0123456789', 'mailcow_cc_username|s:5:"admin";mailcow_cc_role|s:5:"admin";');
        $redis->set('MAIN_NAME', 'Example Mail');
        $snapshot = function () use ($redis): array {
            $keys = array_values(array_filter((array) $redis->keys('*'), fn (string $key): bool => ! str_starts_with($key, 'restv2:')));
            sort($keys);

            return array_combine($keys, array_map(fn (string $key) => $redis->get($key), $keys));
        };
        $before = $snapshot();
        $this->mailcow()->admin('admin', 'Passw0rd!');

        $this->passwordSignIn('admin')->assertCreated();
        $this->getJson('/api/v2/me')->assertOk();
        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->assertSame($before, $snapshot());
    }

    public function test_a_pending_sign_in_needs_the_second_factor(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInPending('alice@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-required');
    }

    public function test_without_a_session_the_caller_is_unauthenticated(): void
    {
        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }
}
