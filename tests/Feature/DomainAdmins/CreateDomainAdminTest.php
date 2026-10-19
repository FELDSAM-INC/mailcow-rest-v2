<?php

namespace Tests\Feature\DomainAdmins;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditLogger;
use App\Support\Hashing\LegacyPasswordVerifier;
use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\TestCase;

/**
 * Feature 007 T025 (AS US1-1 to US1-3, FR-014 to FR-020, research R6): administrators and billing
 * systems create domain administrators with their domains, forced flags and permission flags,
 * together or not at all.
 */
class CreateDomainAdminTest extends TestCase
{
    use AccountTestHelpers;

    private const PASSWORD = 'Str0ng-Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->mailcow()->ensureDomain('xn--bcher-kva.example');
        $this->asAdmin();
    }

    public function test_a_domain_administrator_is_created_with_its_domains(): void
    {
        $response = $this->createDomainAdmin(['username' => 'Reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example', 'b.example']])
            ->assertCreated()
            ->assertHeader('Location', '/api/v2/domain-admins/reseller1');

        $response->assertJsonPath('data.username', 'reseller1')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.forcePasswordChange', false)
            ->assertJsonPath('data.forceSecondFactorSetup', false)
            ->assertJsonPath('data.secondFactorStatus', 'none')
            ->assertJsonPath('data.activePasskeyCount', 0)
            ->assertJsonPath('data.modifiedAt', null)
            ->assertJsonPath('data.domains.0.domain', 'a.example')
            ->assertJsonPath('data.domains.1.domain', 'b.example')
            ->assertJsonPath('data.permissions', $this->flagDefaults());
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('data'));

        $this->assertAdminRow('reseller1', 0, 1);
        $this->assertSame(['force_tfa' => '0', 'force_pw_update' => '0'], $this->adminAttributes('reseller1'));
        $this->assertTrue($this->app->make(LegacyPasswordVerifier::class)->verify((string) DB::table('admin')->where('username', 'reseller1')->value('password'), self::PASSWORD));
        $this->assertAssignments('reseller1', [['a.example', 1], ['b.example', 1]]);
        $this->assertDaAcl('reseller1', array_combine(array_values(self::FLAG_COLUMNS), array_map('intval', array_values($this->flagDefaults()))));

        $this->forgetCookies();
        $this->passwordSignIn('reseller1', self::PASSWORD)->assertCreated()->assertJsonPath('data.role', 'domainAdmin');
    }

    public function test_status_forced_flags_and_permissions_can_be_given(): void
    {
        $this->createDomainAdmin([
            'username' => 'reseller2',
            'password' => self::PASSWORD,
            'domains' => ['a.example'],
            'active' => false,
            'forcePasswordChange' => true,
            'forceSecondFactorSetup' => true,
            'permissions' => ['loginAs' => false, 'unlimitedQuota' => true],
        ])->assertCreated()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.domains.0.active', false)
            ->assertJsonPath('data.permissions.loginAs', false)
            ->assertJsonPath('data.permissions.unlimitedQuota', true)
            ->assertJsonPath('data.permissions.syncjobs', true);

        $this->assertAdminRow('reseller2', 0, 0, true, true);
        $this->assertAssignments('reseller2', [['a.example', 0]]);
        $this->assertDaAcl('reseller2', ['login_as' => 0, 'unlimited_quota' => 1, 'syncjobs' => 1, 'pushover' => 0]);
    }

    public function test_domains_are_normalised_and_merged(): void
    {
        $this->createDomainAdmin(['username' => 'reseller3', 'password' => self::PASSWORD, 'domains' => ['A.example', 'a.example ', 'bücher.example']])
            ->assertCreated()
            ->assertJsonPath('data.domains.1.domain', 'xn--bcher-kva.example')
            ->assertJsonPath('data.domains.1.domainUnicode', 'bücher.example')
            ->assertJsonCount(2, 'data.domains');

        $this->assertAssignments('reseller3', [['a.example', 1], ['xn--bcher-kva.example', 1]]);
    }

    public function test_every_bad_domain_is_named_and_nothing_is_written(): void
    {
        $this->mailcow()->aliasDomain('alias.example', 'a.example');

        $response = $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example', 'nope.example', 'alias.example', 'bad..name']])
            ->assertUnprocessable();

        $response->assertViolation('domains.1', 'notFound')
            ->assertViolation('domains.2', 'notEligible')
            ->assertViolationParams('domains.2', ['kind' => 'aliasDomain'])
            ->assertViolation('domains.3', 'invalidFormat')
            ->assertViolationParams('domains.3', ['format' => 'domainName']);
        $this->assertFalse(DB::table('admin')->where('username', 'reseller1')->exists());
        $this->assertFalse(DB::table('domain_admins')->where('username', 'reseller1')->exists());
        $this->assertFalse(DB::table('da_acl')->where('username', 'reseller1')->exists());
    }

    public function test_an_empty_domain_list_is_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => []])
            ->assertUnprocessable()
            ->assertViolation('domains', 'required');
    }

    public function test_a_username_used_by_any_account_is_a_conflict(): void
    {
        $this->mailcow()->admin('Ops', 'x');
        $this->mailcow()->domainAdmin('reseller', ['a.example']);

        foreach (['OPS', 'ops', 'Reseller'] as $username) {
            $this->createDomainAdmin(['username' => $username, 'password' => self::PASSWORD, 'domains' => ['a.example']])
                ->assertConflict()
                ->assertJsonPath('type', '/api/v2/problems/account-exists');
        }

        $this->assertSame(1, DB::table('domain_admins')->count());
        $this->assertSame(1, DB::table('da_acl')->count());
    }

    public function test_invalid_usernames_are_refused(): void
    {
        $this->createDomainAdmin(['username' => 'API', 'password' => self::PASSWORD, 'domains' => ['a.example']])
            ->assertUnprocessable()
            ->assertViolation('username', 'reserved')
            ->assertViolationParams('username', ['reserved' => ['api']]);
        $this->createDomainAdmin(['username' => 'a b', 'password' => self::PASSWORD, 'domains' => ['a.example']])
            ->assertUnprocessable()
            ->assertViolationParams('username', ['format' => 'accountUsername']);
    }

    public function test_the_password_policy_names_every_unmet_rule(): void
    {
        $this->redis()->hMSet('PASSWD_POLICY', ['length' => '12', 'numbers' => '1', 'lowerupper' => '1']);

        $response = $this->createDomainAdmin(['username' => 'reseller1', 'password' => 'short', 'domains' => ['a.example']])->assertUnprocessable();

        $codes = array_column($response->json('errors.password'), 'code');
        $this->assertSame(['tooShort', 'missingCharacterClass', 'missingCharacterClass'], $codes);
        $this->assertSame(['min' => 12], $response->json('errors.password.0.params'));
        $this->assertFalse(DB::table('admin')->where('username', 'reseller1')->exists());
    }

    public function test_a_pre_hashed_password_is_stored_as_given(): void
    {
        $hash = MailcowRows::hash('SSHA512', 'Whatever-1');

        $this->createDomainAdmin(['username' => 'reseller1', 'password' => $hash, 'domains' => ['a.example']])->assertCreated();

        $this->assertSame($hash, DB::table('admin')->where('username', 'reseller1')->value('password'));
        $this->assertAudit('domainAdminCreation', 'success', 'domainAdmin', 'reseller1', ['passwordPreHashed' => true]);
    }

    public function test_orphaned_rows_of_the_username_are_replaced(): void
    {
        $this->mailcow()->daAclOnly('reseller1', ['login_as' => 0, 'unlimited_quota' => 1]);
        $this->mailcow()->assignmentOnly('reseller1', 'b.example');
        $this->mailcow()->daSso('reseller1', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');

        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example'], 'permissions' => ['bccMaps' => false]])
            ->assertCreated()
            ->assertJsonPath('data.permissions.loginAs', true)
            ->assertJsonPath('data.permissions.unlimitedQuota', false)
            ->assertJsonPath('data.permissions.bccMaps', false);

        $this->assertAssignments('reseller1', [['a.example', 1]]);
        $this->assertSame(1, DB::table('da_acl')->where('username', 'reseller1')->count());
        $this->assertFalse(DB::table('da_sso')->where('username', 'reseller1')->exists());
    }

    public function test_unknown_members_and_flags_are_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();

        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example'], 'colour' => 'red', 'permissions' => ['fooBar' => true]])
            ->assertUnprocessable()
            ->assertViolation('colour', 'unknownField')
            ->assertViolation('permissions.fooBar', 'unknownField');
        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example'], 'active' => 1])
            ->assertUnprocessable()
            ->assertViolation('active', 'invalidType');
        $this->assertFalse(DB::table('admin')->where('username', 'reseller1')->exists());
    }

    public function test_the_creation_is_audited_without_the_password(): void
    {
        $this->captureLog();

        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['b.example', 'a.example']])->assertCreated();

        $record = $this->assertAudit('domainAdminCreation', 'success', 'domainAdmin', 'reseller1', [
            'active' => true,
            'forcePasswordChange' => false,
            'forceSecondFactorSetup' => false,
            'passwordPreHashed' => false,
            'domains' => ['a.example', 'b.example'],
        ]);
        $this->assertSame($this->flagDefaults(), $record->details['permissions']);
        $this->assertSame(['admin', 'admin'], [$record->actor_username, $record->actor_role]);
        $this->assertNoSecretIn(self::PASSWORD, (string) DB::table('admin')->where('username', 'reseller1')->value('password'));
    }

    public function test_nothing_is_stored_when_the_audit_record_cannot_be_written(): void
    {
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::DomainAdminCreation]));

        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example']])->assertStatus(500);

        $this->assertFalse(DB::table('admin')->where('username', 'reseller1')->exists());
        $this->assertFalse(DB::table('domain_admins')->where('username', 'reseller1')->exists());
        $this->assertFalse(DB::table('da_acl')->where('username', 'reseller1')->exists());
    }

    public function test_a_billing_system_creates_one_with_a_read_write_key(): void
    {
        $key = $this->apiKey('admin');
        $this->forgetCookies();

        $this->createDomainAdmin(['username' => 'reseller1', 'password' => self::PASSWORD, 'domains' => ['a.example']], $this->keyHeader($key))
            ->assertCreated();

        $record = $this->assertAudit('domainAdminCreation', 'success', 'domainAdmin', 'reseller1');
        $this->assertSame($key['id'], $record->actor_api_key_id);
    }
}
