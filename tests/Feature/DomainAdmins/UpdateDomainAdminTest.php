<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T028 (FR-021 to FR-024, FR-026, FR-065, FR-072, SC-010, research R7): a merge patch
 * changes exactly the values it sends; read-only values are accepted unchanged; an outdated version
 * changes nothing.
 */
class UpdateDomainAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example', 'b.example'], attributes: ['recovery_email' => 'r@example.net']);
        $this->asAdmin();
    }

    public function test_unsent_members_keep_their_values(): void
    {
        $this->patchDomainAdmin('reseller1', ['forcePasswordChange' => true])->assertOk()
            ->assertJsonPath('data.forcePasswordChange', true)
            ->assertJsonPath('data.forceSecondFactorSetup', false)
            ->assertJsonPath('data.active', true)
            ->assertJsonCount(2, 'data.domains');

        $this->assertAdminRow('reseller1', 0, 1, true, false);
        $this->assertSame('r@example.net', $this->adminAttributes('reseller1')['recovery_email']);
    }

    public function test_empty_and_unknown_members_are_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();

        $this->patchDomainAdmin('reseller1', [])->assertUnprocessable()->assertViolation('body', 'required');
        $this->patchDomainAdmin('reseller1', ['colour' => 'red'])->assertUnprocessable()->assertViolation('colour', 'unknownField');
        $this->patchDomainAdmin('reseller1', ['active' => null])->assertUnprocessable()->assertViolation('active', 'invalidType');
        $this->patchDomainAdmin('reseller1', ['secondFactorStatus' => 'active'])->assertUnprocessable()->assertViolation('secondFactorStatus', 'immutable');
    }

    public function test_read_only_members_are_accepted_unchanged_and_refused_changed(): void
    {
        $current = $this->getJson('/api/v2/domain-admins/reseller1')->json('data');

        $this->patchDomainAdmin('reseller1', [
            'createdAt' => $current['createdAt'],
            'modifiedAt' => $current['modifiedAt'],
            'secondFactorStatus' => $current['secondFactorStatus'],
            'activePasskeyCount' => $current['activePasskeyCount'],
            'etag' => $current['etag'],
            'forceSecondFactorSetup' => true,
        ])->assertOk()->assertJsonPath('data.forceSecondFactorSetup', true);

        $response = $this->patchDomainAdmin('reseller1', ['createdAt' => '2020-01-01T00:00:00Z', 'activePasskeyCount' => 5, 'modifiedAt' => '2020-01-01T00:00:00Z'])->assertUnprocessable();
        $response->assertViolation('createdAt', 'immutable')
            ->assertViolation('activePasskeyCount', 'immutable')
            ->assertViolation('modifiedAt', 'immutable');
    }

    public function test_a_deactivation_applies_to_the_assignments_sessions_and_keys(): void
    {
        $key = $this->apiKey('reseller1');
        $session = $this->signInAs('reseller1', Role::DomainAdmin);
        $this->asAdmin();

        $this->patchDomainAdmin('reseller1', ['active' => false])->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.domains.0.active', false)
            ->assertJsonPath('data.domains.1.active', false);
        $this->assertAssignments('reseller1', [['a.example', 0], ['b.example', 0]]);

        $admin = $this->sessionCookie();
        $this->useSessionCookie($session);
        $this->getJson('/api/v2/me')->assertUnauthorized();
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/api-key-invalid');

        $this->useSessionCookie((string) $admin);
        $this->patchDomainAdmin('reseller1', ['active' => true])->assertOk();
        $this->assertAssignments('reseller1', [['a.example', 1], ['b.example', 1]]);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $this->assertNull(DB::table('restv2_api_keys')->where('id', $key['id'])->value('revoked_at'));
    }

    public function test_a_password_change_keeps_flags_sessions_and_keys(): void
    {
        DB::table('admin')->where('username', 'reseller1')->update(['attributes' => '{"force_tfa": "1", "force_pw_update": "1"}']);
        $key = $this->apiKey('reseller1');
        $session = $this->signInAs('reseller1', Role::DomainAdmin);
        $this->asAdmin();

        $this->patchDomainAdmin('reseller1', ['password' => 'N3w-Passw0rd!'])->assertOk();

        $hash = (string) DB::table('admin')->where('username', 'reseller1')->value('password');
        $this->assertTrue($this->app->make(LegacyPasswordVerifier::class)->verify($hash, 'N3w-Passw0rd!'));
        $this->assertAdminRow('reseller1', 0, 1, true, true);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();
        $this->useSessionCookie($session);
        $this->getJson('/api/v2/me')->assertOk();
    }

    public function test_an_outdated_version_changes_nothing(): void
    {
        $etag = $this->getJson('/api/v2/domain-admins/reseller1')->json('data.etag');
        // A legacy edit of the same account.
        DB::table('admin')->where('username', 'reseller1')->update(['attributes' => '{"force_tfa": "1", "force_pw_update": "0"}']);

        $this->patchDomainAdmin('reseller1', ['active' => false], ['If-Match' => $etag])->assertStatus(412);

        $this->assertAdminRow('reseller1', 0, 1, false, true);
        $current = $this->getJson('/api/v2/domain-admins/reseller1')->assertOk();
        $current->assertJsonPath('data.forceSecondFactorSetup', true);
        $this->assertNotSame($etag, $current->json('data.etag'));
        $this->patchDomainAdmin('reseller1', ['active' => false], ['If-Match' => (string) $current->json('data.etag')])->assertOk();
    }

    public function test_a_patch_that_changes_nothing_writes_no_audit_record(): void
    {
        $before = $this->getJson('/api/v2/domain-admins/reseller1')->json('data.etag');

        $this->patchDomainAdmin('reseller1', ['active' => true, 'forcePasswordChange' => false])->assertOk()->assertJsonPath('data.etag', $before);

        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'domainAdminUpdate')->count());
    }

    public function test_changes_are_audited_with_old_and_new_values(): void
    {
        $this->captureLog();

        $this->patchDomainAdmin('reseller1', ['active' => false, 'forceSecondFactorSetup' => true, 'password' => 'Secr3t-Passw0rd!'])->assertOk();

        $record = $this->assertAudit('domainAdminUpdate', 'success', 'domainAdmin', 'reseller1', [
            'changes' => ['active' => ['old' => true, 'new' => false], 'forceSecondFactorSetup' => ['old' => false, 'new' => true]],
            'passwordChanged' => true,
            'passwordPreHashed' => false,
        ]);
        $this->assertArrayNotHasKey('domainsAdded', $record->details);
        $this->assertNoSecretIn('Secr3t-Passw0rd!', (string) DB::table('admin')->where('username', 'reseller1')->value('password'));
    }

    public function test_an_unknown_account_and_an_administrator_are_not_found(): void
    {
        $this->patchDomainAdmin('nobody', ['active' => false])->assertNotFound();
        $this->patchDomainAdmin('admin', ['active' => false])->assertNotFound();
        $this->assertAdminRow('admin', 1, 1);
    }
}
