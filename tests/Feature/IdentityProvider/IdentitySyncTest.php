<?php

namespace Tests\Feature\IdentityProvider;

use App\Casts\MailcowDateTime;
use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

class IdentitySyncTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig();
        $this->asAdmin();
    }

    public function test_capability_and_writes_are_gated_by_the_upstream_schema(): void
    {
        $modern = SchemaVersion::hasNovember2026Features();
        $this->getJson('/api/v2/identity-provider/synchronisation')->assertOk()
            ->assertJsonPath('data.supported', $modern)->assertJsonPath('data.canSync', $modern);
        $this->getJson('/api/v2/capabilities/identity-provider')->assertOk()->assertJsonPath('data.identitySynchronisation', $modern);
        if (! $modern) {
            $this->patchMerge('/api/v2/identity-provider/synchronisation', ['offboarding' => true], $this->csrf())->assertConflict();
            $this->postJson('/api/v2/identity-provider/synchronisation/runs', ['mode' => 'preview'], $this->csrf())->assertConflict();
        }
    }

    private function modern(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Requires the 2026-11 upstream schema.');
        }
    }

    public function test_settings_keep_provider_secrets_and_survive_a_main_provider_save(): void
    {
        $this->modern();
        $before = $this->providerRows();
        $etag = $this->getJson('/api/v2/identity-provider/synchronisation')->json('data.etag');
        $this->patchMerge('/api/v2/identity-provider/synchronisation', [
            'offboarding' => true, 'offboardingLimit' => 15, 'groupSync' => true,
            'groupMemberMode' => 'ad_chain', 'groupDefaultDomain' => 'example.org',
            'groupFilter' => '(objectClass=group)', 'groupBaseDn' => 'ou=groups,dc=example,dc=org',
        ], $this->csrf() + ['If-Match' => $etag])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertSame($before['bindpass'], $this->providerRows()['bindpass']);
        $this->patchProvider(['syncInterval' => 30])->assertOk();
        $this->assertSame('ad_chain', $this->providerRows()['group_member_mode']);
        $this->assertSame('1', $this->providerRows()['offboarding']);
        $this->patchMerge('/api/v2/identity-provider/synchronisation', ['offboarding' => false], $this->csrf() + ['If-Match' => $etag])->assertStatus(412);
        $this->assertDatabaseHas('restv2_audit_events', ['event_type' => 'identitySyncSettingsUpdate', 'outcome' => 'success']);
    }

    public function test_changing_ldap_identity_namespace_preserves_offboarding_markers(): void
    {
        $this->modern();
        $this->mailcow()->mailbox('carl@example.org', authsource: 'ldap');
        DB::table('identity_link')->insert(['username' => 'carl@example.org', 'provider' => 'ldap', 'external_id' => 'old-id', 'idp_disabled' => 1, 'missing_since' => '2026-11-06 12:00:00']);
        $this->patchMerge('/api/v2/identity-provider/synchronisation', ['idAttribute' => 'entryUUID'], $this->csrf())->assertOk();
        $this->assertDatabaseHas('identity_link', ['username' => 'carl@example.org', 'external_id' => null, 'idp_disabled' => 1, 'missing_since' => '2026-11-06 12:00:00']);
    }

    public function test_invalid_members_and_wrong_provider_fields_cannot_change_settings(): void
    {
        $this->modern();
        $this->withoutRequestValidation();
        foreach ([['offboarding' => 'true'], ['offboardingLimit' => 101], ['idAttribute' => null], ['groupMemberMode' => 'other'], ['groupFilter' => '(broken'], ['bindPassword' => 'injected'], ['groupPaths' => ['/staff']]] as $patch) {
            $this->patchMerge('/api/v2/identity-provider/synchronisation', $patch, $this->csrf())->assertUnprocessable();
        }
        $this->postJson('/api/v2/identity-provider/synchronisation/runs?mode=run', [], $this->csrf())->assertUnprocessable();
        $this->assertDatabaseCount('identity_sync_run', 0);
    }

    public function test_preview_and_force_are_queued_once_and_force_requires_confirmation(): void
    {
        $this->modern();
        $first = $this->postJson('/api/v2/identity-provider/synchronisation/runs', ['mode' => 'preview'], $this->csrf())
            ->assertAccepted()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v2/identity-provider/synchronisation/runs', ['mode' => 'preview'], $this->csrf())
            ->assertAccepted()->assertJsonPath('data.id', $first);
        $this->postJson('/api/v2/identity-provider/synchronisation/runs', ['mode' => 'force'], $this->csrf())->assertUnprocessable();
        $this->postJson('/api/v2/identity-provider/synchronisation/runs', ['mode' => 'force', 'confirmForce' => true], $this->csrf())->assertAccepted();
        $this->assertDatabaseCount('identity_sync_run', 2);
        DB::table('identity_sync_run')->where('id', $first)->update(['status' => 'blocked', 'summary' => json_encode(['blocked' => true, 'details' => ['conflicts' => ['Address already linked']], 'clientSecret' => 'do-not-return'])]);
        $response = $this->getJson('/api/v2/identity-provider/synchronisation')->assertOk();
        $response->assertJsonPath('data.runs.1.summary.details.conflicts.0', 'Address already linked');
        $this->assertStringNotContainsString('do-not-return', $response->getContent());
    }

    public function test_unlink_requires_current_version_and_does_not_enable_mailbox(): void
    {
        $this->modern();
        $this->mailcow()->mailbox('carl@example.org', authsource: 'ldap', active: 2);
        DB::table('identity_link')->insert(['username' => 'carl@example.org', 'provider' => 'ldap', 'external_id' => 'id-1', 'idp_disabled' => 1]);
        $url = '/api/v2/identity-provider/links/carl%40example.org';
        $etag = $this->getJson($url)->assertOk()->assertJsonPath('data.disabled', true)->json('data.etag');
        $this->deleteJson($url, [], $this->csrf() + ['If-Match' => '"stale"'])->assertStatus(412);
        $this->deleteJson($url, [], $this->csrf() + ['If-Match' => $etag])->assertNoContent();
        $this->assertDatabaseHas('mailbox', ['username' => 'carl@example.org', 'active' => 2]);
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
    }

    public function test_roles_read_only_keys_and_csrf_are_enforced(): void
    {
        $this->modern();
        $url = '/api/v2/identity-provider/synchronisation';
        $this->patchMerge($url, ['offboarding' => true])->assertForbidden();
        $this->forgetCookies();
        $this->asDomainAdmin(['example.org']);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url.'/runs', ['mode' => 'preview'], $this->csrf())->assertForbidden();
        $this->forgetCookies();
        $key = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $this->getJson($url, $key)->assertOk();
        $this->patchMerge($url, ['offboarding' => true], $key)->assertForbidden();
        $this->postJson($url.'/runs', ['mode' => 'run'], $key)->assertForbidden();
        $this->assertDatabaseCount('identity_sync_run', 0);
    }

    public function test_status_uses_database_runs_and_ignores_previews_and_other_providers(): void
    {
        $this->modern();
        $this->freezeTime();
        $this->mailcow()->identityProviderRows(['periodic_sync' => '1', 'sync_interval' => '15']);
        $etag = $this->getJson('/api/v2/identity-provider')->assertOk()->json('data.etag');
        $time = fn ($date) => $date->copy()->setTimezone(MailcowDateTime::zone())->format('Y-m-d H:i:s');
        DB::table('identity_sync_run')->insert(['provider' => 'ldap', 'mode' => 'run', 'status' => 'ok',
            'started' => $time(now()->subMinutes(4)), 'finished' => $time(now()->subMinutes(3))]);
        DB::table('identity_sync_run')->insert(['provider' => 'ldap', 'mode' => 'preview', 'status' => 'running']);
        DB::table('identity_sync_run')->insert(['provider' => 'keycloak', 'mode' => 'run', 'status' => 'running']);
        $this->getJson('/api/v2/identity-provider')->assertOk()->assertJsonPath('data.etag', $etag)
            ->assertJsonPath('data.synchronisation.state', 'waiting')
            ->assertJsonPath('data.synchronisation.lastCompletedAt', now()->subMinutes(3)->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.synchronisation.nextRunExpectedAt', now()->addMinutes(11)->utc()->format('Y-m-d\TH:i:s\Z'));
        DB::table('identity_sync_run')->insert(['provider' => 'ldap', 'mode' => 'run', 'status' => 'pending']);
        $this->getJson('/api/v2/identity-provider')->assertOk()->assertJsonPath('data.synchronisation.state', 'running');
        DB::table('identity_sync_run')->where('status', 'pending')->delete();
        $this->travel(17)->minutes();
        $this->getJson('/api/v2/identity-provider')->assertOk()->assertJsonPath('data.synchronisation.state', 'overdue');
        $this->mailcow()->identityProviderRows(['periodic_sync' => '0', 'import_users' => '0', 'offboarding' => '1']);
        $this->getJson('/api/v2/identity-provider')->assertOk()->assertJsonPath('data.synchronisation.enabled', true);
        $this->mailcow()->identityProviderRows(['offboarding' => '0']);
        $this->getJson('/api/v2/identity-provider')->assertOk()->assertJsonPath('data.synchronisation.state', 'disabled');
    }
}
