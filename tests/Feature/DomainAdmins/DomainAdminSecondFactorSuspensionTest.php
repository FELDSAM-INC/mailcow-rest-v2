<?php

namespace Tests\Feature\DomainAdmins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T032 (AS US1-6, FR-027, research R13): suspending the second factors makes every
 * entry inactive until the next password sign-in, which re-activates them; passkeys stay.
 */
class DomainAdminSecondFactorSuspensionTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->asAdmin();
    }

    public function test_the_entries_are_suspended_until_the_next_password_sign_in(): void
    {
        $this->secondFactorsFor('reseller1', passkeys: 1);

        $response = $this->suspendSecondFactors('domain-admins', 'reseller1')->assertOk()
            ->assertJsonPath('data.secondFactorStatus', 'suspended')
            ->assertJsonPath('data.activePasskeyCount', 1);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));

        $this->assertSame([0], DB::table('tfa')->where('username', 'reseller1')->pluck('active')->map(fn ($a): int => (int) $a)->unique()->values()->all());
        $this->assertSame(1, (int) DB::table('fido2')->where('username', 'reseller1')->value('active'));
        $this->assertAudit('domainAdminSecondFactorSuspension', 'success', 'domainAdmin', 'reseller1', ['suspendedCount' => 2]);

        $this->forgetCookies();
        $this->passwordSignIn('reseller1')->assertCreated()->assertJsonPath('data.state', 'active');
        $this->assertSame([1], DB::table('tfa')->where('username', 'reseller1')->pluck('active')->map(fn ($a): int => (int) $a)->unique()->values()->all());
    }

    public function test_an_account_without_active_entries_is_unchanged(): void
    {
        $this->mailcow()->tfaTotp('reseller1', 'S', 'Phone', 0);

        $this->suspendSecondFactors('domain-admins', 'reseller1')->assertOk()->assertJsonPath('data.secondFactorStatus', 'suspended');
        $this->suspendSecondFactors('domain-admins', 'ReSeller1')->assertOk();

        $records = DB::table('restv2_audit_events')->where('event_type', 'domainAdminSecondFactorSuspension')->pluck('details');
        $this->assertCount(2, $records);
        $this->assertSame(0, json_decode((string) $records[0], true)['suspendedCount']);
    }

    public function test_an_outdated_version_changes_nothing(): void
    {
        $this->secondFactorsFor('reseller1');
        $etag = $this->getJson('/api/v2/domain-admins/reseller1')->json('data.etag');
        DB::table('admin')->where('username', 'reseller1')->update(['active' => 0]);

        $this->suspendSecondFactors('domain-admins', 'reseller1', ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame(2, DB::table('tfa')->where('username', 'reseller1')->where('active', 1)->count());
    }

    public function test_unknown_accounts_are_not_found(): void
    {
        $this->suspendSecondFactors('domain-admins', 'admin')->assertNotFound();
        $this->suspendSecondFactors('domain-admins', 'nobody')->assertNotFound();
    }
}
