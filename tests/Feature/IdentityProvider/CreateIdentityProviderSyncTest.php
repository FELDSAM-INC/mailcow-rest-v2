<?php

namespace Tests\Feature\IdentityProvider;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

class CreateIdentityProviderSyncTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
    }

    private function modern(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Requires the 2026-11 schema.');
        }
    }

    public function test_ldap_and_keycloak_first_setup_save_sync_settings_with_the_provider(): void
    {
        $this->modern();
        foreach (['ldap', 'keycloak'] as $type) {
            DB::table('identity_provider')->delete();
            $settings = ['offboarding' => true, 'offboardingLimit' => 25, 'groupSync' => true,
                'groupAutocreate' => false, 'groupDefaultDomain' => 'example.org'];
            $settings += $type === 'ldap'
                ? ['idAttribute' => 'entryUUID', 'disabledFilter' => '(disabled=TRUE)', 'groupBaseDn' => 'ou=groups,dc=example,dc=org', 'groupFilter' => '(objectClass=group)', 'groupNameAttribute' => 'cn', 'groupMemberMode' => 'ad_chain', 'groupMemberAttribute' => 'member']
                : ['groupPaths' => ['/staff', '/teams/*']];
            $response = $this->putProvider(IdentityProviderFixtures::$type(['syncSettings' => $settings]), ['If-None-Match' => '*'])->assertCreated();
            $read = $this->getJson('/api/v2/identity-provider/synchronisation')->assertOk()
                ->assertJsonPath('data.provider', $type)->assertJsonPath('data.settings.offboarding', true)
                ->assertJsonPath('data.settings.groupSync', true)->assertJsonPath('data.settings.offboardingLimit', 25)
                ->assertJsonPath('data.settings.groupDefaultDomain', 'example.org')
                ->assertJsonPath('data.etag', $response->json('data.etag'));
            if ($type === 'ldap') {
                $read->assertJsonPath('data.settings.idAttribute', 'entryuuid')->assertJsonPath('data.settings.groupMemberMode', 'ad_chain');
            } else {
                $read->assertJsonPath('data.settings.groupPaths', ['/staff', '/teams/*']);
            }
            $this->assertDatabaseHas('identity_provider', ['key' => 'offboarding', 'value' => '1']);
            $this->assertDatabaseHas('identity_provider', ['key' => 'group_sync', 'value' => '1']);
        }
        $this->assertCount(2, $this->providerAudit('identitySyncSettingsUpdate'));
        $this->assertStringNotContainsString('ldap-secret', (string) json_encode($this->allAudit()));
        $this->assertStringNotContainsString('kc-secret', (string) json_encode($this->allAudit()));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSettings(): iterable
    {
        foreach ([null, '', [], false, ['groupSync' => 'true'], ['offboardingLimit' => 101], ['groupDefaultDomain' => 'missing.example'], ['groupMemberMode' => 'invalid'], ['groupFilter' => '(broken'], ['idAttribute' => null], ['groupPaths' => ['/staff']], ['bindPassword' => 'injected'], ['unknown.*' => true]] as $index => $settings) {
            yield 'invalid '.$index => [$settings];
        }
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_sync_settings_do_not_partially_create_a_provider(mixed $settings): void
    {
        $this->withoutRequestValidation();
        $this->modern();
        $this->putProvider(IdentityProviderFixtures::ldap(['syncSettings' => $settings]))->assertUnprocessable();
        $this->assertSame([], $this->providerRows());
        $this->assertCount(0, array_filter($this->providerAudit('identityProviderCreation'), fn ($event) => $event->outcome === 'success'));
        $this->assertCount(0, $this->providerAudit('identitySyncSettingsUpdate'));
    }

    public function test_invalid_provider_type_connection_tests_and_create_preconditions_cannot_apply_foreign_settings(): void
    {
        $this->withoutRequestValidation();
        $this->modern();
        $this->putProvider(IdentityProviderFixtures::genericOidc(['syncSettings' => ['groupSync' => true]]))->assertUnprocessable();
        $this->putProvider(IdentityProviderFixtures::keycloak(['syncSettings' => ['idAttribute' => 'entryuuid']]))->assertUnprocessable();
        $this->testConnection(IdentityProviderFixtures::ldap(['syncSettings' => ['groupSync' => 'true']]))->assertUnprocessable();
        $this->assertSame([], $this->providerRows());
        $this->putProvider(IdentityProviderFixtures::ldap(['syncSettings' => ['groupSync' => true]]), ['If-None-Match' => '*'])->assertCreated();
        $rows = $this->providerRows();
        $this->putProvider(IdentityProviderFixtures::ldap(['syncSettings' => ['groupSync' => false]]), ['If-None-Match' => '*'])->assertStatus(412);
        $this->assertSame($rows, $this->providerRows());
        $this->assertCount(1, $this->providerAudit('identitySyncSettingsUpdate'));
    }

    public function test_connection_test_accepts_valid_setup_settings_but_persists_nothing(): void
    {
        $this->modern();
        $this->ldapFake();
        $this->testConnection(IdentityProviderFixtures::ldap(['syncSettings' => ['offboarding' => true, 'groupSync' => true]]))
            ->assertOk()->assertJsonPath('data.outcome', 'success');
        $this->assertSame([], $this->providerRows());
        $this->assertCount(0, $this->providerAudit('identitySyncSettingsUpdate'));
    }

    public function test_old_mailcow_rejects_sync_settings_without_creating_the_provider(): void
    {
        if (SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('For pre-2026-11 compatibility fixtures.');
        }
        $this->putProvider(IdentityProviderFixtures::ldap(['syncSettings' => ['groupSync' => true]]))->assertUnprocessable();
        $this->assertSame([], $this->providerRows());
        $this->putProvider(IdentityProviderFixtures::ldap())->assertCreated();
    }
}
