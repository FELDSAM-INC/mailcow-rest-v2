<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T039 (FR-006, FR-030, US2 AS-11): the capabilities read reports, for the caller,
 * whether custom imapsync parameters are allowed and the allow-list in effect (never
 * `showpasswords`), the bulk limit, the page sizes, the field limits and the creation defaults. It
 * changes nothing, read-only keys may use it, and the restriction to administrators is enforced on
 * creation with `customParamsNotPermitted`.
 */
class SyncJobCapabilitiesTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['syncjobs' => 1]);
    }

    public function test_every_caller_with_the_flag_gets_the_defaults_limits_and_allow_list(): void
    {
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $data = (array) $this->getJson('/api/v2/capabilities/sync-jobs')->assertOk()->json('data');

        $this->assertSame(['allowed' => true, 'restrictedToAdministrators' => false], array_diff_key($data['customParameters'], ['allowedOptions' => true]));
        $this->assertCount(113, $data['customParameters']['allowedOptions']);
        $this->assertNotContains('showpasswords', $data['customParameters']['allowedOptions']);
        $this->assertSame('abort', $data['customParameters']['allowedOptions'][0]);
        $this->assertSame(100, $data['bulkLimit']);
        $this->assertSame(['default' => 25, 'maximum' => 1000], $data['pageSize']);
        $this->assertSame([
            'remotePort' => ['minimum' => 1, 'maximum' => 65535],
            'intervalMinutes' => ['minimum' => 1, 'maximum' => 43800],
            'maxAgeDays' => ['minimum' => 0, 'maximum' => 32000],
            'maxBytesPerSecond' => ['minimum' => 0, 'maximum' => 125000000],
            'remoteTimeoutSeconds' => ['minimum' => 1, 'maximum' => 32000],
            'localTimeoutSeconds' => ['minimum' => 1, 'maximum' => 32000],
            'remoteHostMaxLength' => 255,
            'remoteUserMaxLength' => 255,
            'remotePasswordMaxLength' => 255,
            'destinationSubfolderMaxLength' => 255,
            'excludeMaxLength' => 500,
            'customParametersMaxLength' => 512,
        ], $data['limits']);
        $this->assertSame(['ssl', 'starttls', 'plain'], $data['encryptions']);
        $this->assertSame('(?i)spam|(?i)junk', $data['defaults']['exclude']);
        $this->assertSame(20, $data['defaults']['intervalMinutes']);
        $this->assertSame('ssl', $data['defaults']['encryption']);
        $this->assertTrue($data['defaults']['active']);
        $this->assertSame(0, DB::table('restv2_audit_events')->count());

        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/capabilities/sync-jobs')->assertOk()->assertJsonPath('data.customParameters.allowed', true);
    }

    public function test_the_restriction_and_the_bulk_limit_follow_the_server_settings(): void
    {
        config(['restv2.sync_jobs.custom_params_admin_only' => true, 'restv2.sync_jobs.bulk_limit' => 50]);

        foreach ([['alice@example.org', Role::MailboxUser, false], ['reseller', Role::DomainAdmin, false], ['admin', Role::Admin, true]] as [$username, $role, $allowed]) {
            $this->signInAs($username, $role);
            $this->getJson('/api/v2/capabilities/sync-jobs')->assertOk()
                ->assertJsonPath('data.customParameters.allowed', $allowed)
                ->assertJsonPath('data.customParameters.restrictedToAdministrators', true)
                ->assertJsonPath('data.bulkLimit', 50);
        }
    }

    public function test_showpasswords_is_never_listed_even_when_the_allow_list_contains_it(): void
    {
        config(['restv2.vars_overrides.IMAPSYNC_OPTIONS' => '{"whitelist":["maxsize","showpasswords","MixedCase"],"blacklist":[]}']);
        $this->app->forgetInstance(MailcowConfig::class);
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/capabilities/sync-jobs')->assertOk()->assertJsonPath('data.customParameters.allowedOptions', ['maxsize', 'MixedCase']);
    }

    public function test_read_only_keys_may_read_it_and_callers_without_the_flag_may_not(): void
    {
        $this->getJson('/api/v2/capabilities/sync-jobs', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
        $this->getJson('/api/v2/capabilities/sync-jobs', $this->keyHeader($this->apiKey('reseller', 'readOnly')))->assertOk();

        DB::table('da_acl')->where('username', 'reseller')->update(['syncjobs' => 0]);
        $this->getJson('/api/v2/capabilities/sync-jobs', $this->keyHeader($this->apiKey('reseller', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required');

        DB::table('user_acl')->where('username', 'alice@example.org')->update(['syncjobs' => 0]);
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/capabilities/sync-jobs')->assertForbidden()->assertJsonPath('requiredFlags', ['syncjobs']);
    }

    public function test_a_restricted_user_s_custom_parameters_are_refused_and_an_administrator_s_accepted(): void
    {
        config(['restv2.sync_jobs.custom_params_admin_only' => true]);
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $this->createSyncJob(self::syncJobBody(['customParameters' => '--maxsize=1']))->assertUnprocessable()
            ->assertJsonPath('errors.customParameters.0.code', 'customParamsNotPermitted');
        $this->assertSame(0, $this->syncJobCount());

        $this->signInAs('admin', Role::Admin);
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org', 'customParameters' => '--maxsize=1']))->assertCreated()
            ->assertJsonPath('data.customParameters', '--maxsize=1');
    }
}
