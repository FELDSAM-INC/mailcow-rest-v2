<?php

namespace Tests\Feature\Impersonation;

use App\Auth\PermissionFlag;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T052 (FR-046 to FR-048, research R18): during an impersonation the identity describes
 * the target with the flags legacy grants to impersonators, names the impersonator, and keeps the
 * impersonator's authentication, pending actions and preferences.
 */
class ImpersonationIdentityTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast(['syncjobs' => 0, 'pushover' => 0, 'unlimited_quota' => 1, 'quarantine' => 1]);
        DB::table('user_acl')->where('username', 'carol@a.example')->update(['syncjobs' => 0, 'app_passwds' => 0, 'spam_alias' => 0]);
    }

    public function test_the_identity_describes_the_target_and_names_the_impersonator(): void
    {
        $this->preferencesFor('alice', language: 'cs-cz');
        $this->signInAs('alice', Role::Admin);
        $started = $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated()->json('data');

        $identity = $this->getJson('/api/v2/me')->assertOk()->json('data');

        $this->assertSame('bob@a.example', $identity['username']);
        $this->assertSame('mailboxUser', $identity['role']);
        $this->assertSame(['type' => 'mailbox', 'mailbox' => 'bob@a.example', 'domain' => 'a.example'], $identity['scope']);
        $this->assertSame(['method' => 'password'], $identity['authentication']);
        $this->assertSame('cs-cz', $identity['preferences']['language']);
        $this->assertSame($started['id'], $identity['impersonation']['id']);
        $this->assertSame(['username' => 'alice', 'role' => 'admin', 'authMethod' => 'password'], $identity['impersonation']['impersonator']);
        $this->assertSame(['username' => 'bob@a.example', 'role' => 'mailboxUser'], $identity['impersonation']['target']);
        $this->assertSame(array_fill_keys(array_map(fn (PermissionFlag $f): string => $f->value, PermissionFlag::cases()), true), $identity['permissions']);
    }

    public function test_a_domain_administrator_impersonator_brings_its_own_overlapping_flags(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->impersonate('mailboxUser', 'carol@a.example')->assertCreated();

        $permissions = $this->getJson('/api/v2/me')->assertOk()->json('data.permissions');

        foreach (PermissionFlag::cases() as $flag) {
            $own = DB::table('da_acl')->where('username', 'reseller1')->value($flag->daAclColumn() ?? 'username');
            $expected = $flag->daAclColumn() === null ? true : (int) $own === 1;
            $this->assertSame($expected, $permissions[$flag->value], $flag->value);
        }

        $this->assertFalse($permissions['syncjobs']);
        $this->assertFalse($permissions['pushover']);
        $this->assertTrue($permissions['appPasswds']);
        $this->assertTrue($permissions['spamAlias']);
        $this->assertTrue($permissions['unlimitedQuota']);
    }

    public function test_the_target_forced_actions_do_not_restrict_the_impersonation(): void
    {
        DB::table('mailbox')->where('username', 'bob@a.example')->update(['attributes' => DB::raw("JSON_SET(attributes, '$.force_pw_update', '1')")]);
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.pendingActions', []);
        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], $this->csrf())->assertOk();
    }

    public function test_an_impersonator_forced_to_change_its_password_is_restricted_but_can_end(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);

        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.pendingActions', ['passwordChange']);
        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], $this->csrf())->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        $this->getJson('/api/v2/session/impersonation')->assertOk();
        $this->endImpersonation()->assertNoContent();
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'alice');
    }

    public function test_a_preference_change_changes_the_impersonator_preferences(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();

        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'], $this->csrf())->assertOk();

        $this->assertSame('dark', DB::table('restv2_preferences')->where('account_kind', 'admin')->where('username', 'alice')->value('theme'));
        $this->assertFalse(DB::table('restv2_preferences')->where('account_kind', 'mailbox')->exists());
    }

    public function test_the_session_and_the_impersonation_reads_report_it(): void
    {
        $this->signInAs('alice', Role::Admin);
        $this->getJson('/api/v2/session/impersonation')->assertNotFound();
        $id = $this->impersonate('domainAdmin', 'reseller1')->assertCreated()->json('data.id');

        $session = $this->getJson('/api/v2/session')->assertOk();
        $session->assertJsonPath('data.username', 'reseller1')
            ->assertJsonPath('data.role', 'domainAdmin')
            ->assertJsonPath('data.authMethod', 'password')
            ->assertJsonPath('data.impersonation.id', $id)
            ->assertJsonPath('data.impersonation.impersonator.username', 'alice');
        $this->getJson('/api/v2/session/impersonation')->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.target', ['username' => 'reseller1', 'role' => 'domainAdmin']);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.webmailOnBehalfEnabled', false);
    }
}
