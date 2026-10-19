<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T047 (AS US2-1, US2-3 to US2-6, FR-037 to FR-041): administrators read the 19 flags
 * and change only the ones they send; the change applies to the domain administrator's next
 * request; nobody else can change them.
 */
class DomainAdminPermissionsTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->asAdmin();
    }

    public function test_a_new_account_reads_the_schema_defaults(): void
    {
        $permissions = $this->getJson('/api/v2/domain-admins/reseller1')->assertOk()->json('data.permissions');

        $this->assertSame($this->flagDefaults(), $permissions);
        $this->assertTrue($permissions['loginAs']);
        $this->assertFalse($permissions['unlimitedQuota']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function flags(): array
    {
        $names = ['syncjobs', 'quarantine', 'loginAs', 'sogoAccess', 'appPasswds', 'bccMaps', 'pushover', 'filters', 'ratelimit', 'spamPolicy',
            'extendSenderAcl', 'unlimitedQuota', 'protocolAccess', 'smtpIpAccess', 'aliasDomains', 'mailboxRelayhost', 'domainRelayhost', 'domainDesc', 'aliasExternalGoto'];

        return array_combine($names, array_map(fn (string $n): array => [$n], $names));
    }

    #[DataProvider('flags')]
    public function test_each_flag_changes_exactly_its_column_and_applies_at_the_next_request(string $flag): void
    {
        $key = $this->apiKey('reseller1', 'readOnly');
        $before = DB::table('da_acl')->where('username', 'reseller1')->first();
        $column = self::FLAG_COLUMNS[$flag];
        $negated = (int) $before->{$column} !== 1;

        $this->patchDomainAdmin('reseller1', ['permissions' => [$flag => $negated]])->assertOk()->assertJsonPath("data.permissions.{$flag}", $negated);

        $after = (array) DB::table('da_acl')->where('username', 'reseller1')->first();
        $expected = (array) $before;
        $expected[$column] = $negated ? 1 : 0;
        $this->assertEquals($expected, $after);
        $this->assertAudit('domainAdminUpdate', 'success', 'domainAdmin', 'reseller1', ['permissionsChanged' => [$flag => ['old' => ! $negated, 'new' => $negated]]]);

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk()->assertJsonPath("data.permissions.{$flag}", $negated);
        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath("data.permissions.{$flag}", $negated);
    }

    public function test_an_unknown_flag_is_refused_and_nothing_changes_when_invalid(): void
    {
        $this->withoutRequestValidation();
        $before = (array) DB::table('da_acl')->where('username', 'reseller1')->first();

        $this->patchDomainAdmin('reseller1', ['permissions' => ['fooBar' => true, 'loginAs' => false]])->assertUnprocessable()
            ->assertViolation('permissions.fooBar', 'unknownField');
        $this->patchDomainAdmin('reseller1', ['permissions' => ['loginAs' => 'yes']])->assertUnprocessable()
            ->assertViolation('permissions.loginAs', 'invalidType');

        $this->assertEquals($before, (array) DB::table('da_acl')->where('username', 'reseller1')->first());
    }

    public function test_a_domain_administrator_cannot_change_its_own_flags(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);

        $this->patchDomainAdmin('reseller1', ['permissions' => ['unlimitedQuota' => true]])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->assertDaAcl('reseller1', ['unlimited_quota' => 0]);
    }

    public function test_the_second_of_two_saves_with_the_same_version_is_outdated(): void
    {
        $etag = (string) $this->getJson('/api/v2/domain-admins/reseller1')->json('data.etag');

        $this->patchDomainAdmin('reseller1', ['permissions' => ['bccMaps' => false]], ['If-Match' => $etag])->assertOk();
        $this->patchDomainAdmin('reseller1', ['permissions' => ['quarantine' => false]], ['If-Match' => $etag])->assertStatus(412);

        $this->assertDaAcl('reseller1', ['bcc_maps' => 0, 'quarantine' => 1]);
    }

    public function test_without_a_permission_record_every_flag_is_off_and_a_change_creates_it(): void
    {
        DB::table('da_acl')->where('username', 'reseller1')->delete();
        $this->assertSame([false], array_values(array_unique($this->getJson('/api/v2/domain-admins/reseller1')->json('data.permissions'))));

        $this->patchDomainAdmin('reseller1', ['permissions' => ['loginAs' => true]])->assertOk()
            ->assertJsonPath('data.permissions.loginAs', true)
            ->assertJsonPath('data.permissions.syncjobs', false);

        $row = (array) DB::table('da_acl')->where('username', 'reseller1')->first();
        unset($row['username']);
        $this->assertSame(1, (int) $row['login_as']);
        $this->assertSame(1, array_sum(array_map('intval', $row)));
        $this->assertAudit('domainAdminUpdate', 'success', 'domainAdmin', 'reseller1', ['permissionsChanged' => ['loginAs' => ['old' => false, 'new' => true]]]);
    }

    public function test_an_unchanged_flag_is_not_a_change(): void
    {
        $this->patchDomainAdmin('reseller1', ['permissions' => ['loginAs' => true]])->assertOk();

        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'domainAdminUpdate')->count());
    }
}
