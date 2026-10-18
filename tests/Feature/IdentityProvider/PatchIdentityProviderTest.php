<?php

namespace Tests\Feature\IdentityProvider;

use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T034 (FR-009, FR-016, US1 AS-2, AS-9): merge patches of the stored configuration.
 */
class PatchIdentityProviderTest extends TestCase
{
    use IdentityProviderTestHelpers;

    public function test_a_patch_keeps_the_other_settings_and_clears_the_service_token(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->keycloakConfig(['access_token' => 'cached-service-token', 'mailpassword_flow' => '1']);
        $before = $this->providerRows();
        $this->asAdmin();

        $response = $this->patchProvider(['syncInterval' => 45])->assertOk();

        $response->assertJsonPath('data.syncInterval', 45)->assertJsonPath('data.mailpasswordFlow', true);
        $expected = array_merge($before, ['sync_interval' => '45', 'access_token' => '']);
        $this->assertSame($expected, $this->providerRows());

        $audit = $this->providerAudit('identityProviderUpdate');
        $this->assertCount(1, $audit);
        $this->assertSame(['syncInterval' => ['old' => 15, 'new' => 45]], $audit[0]->details['changes']);
    }

    public function test_null_clears_nullable_settings_and_arrays_replace(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->mailboxTemplate('Students');
        $this->mailcow()->ldapConfig(['default_template' => 'Staff']);
        $this->asAdmin();

        $this->patchProvider([
            'defaultTemplate' => null,
            'filter' => null,
            'attributeField' => null,
            'attributeMapping' => [['attributeValue' => 'student', 'template' => 'Students']],
        ])->assertOk()
            ->assertJsonPath('data.defaultTemplate', null)
            ->assertJsonPath('data.filter', null)
            ->assertJsonPath('data.attributeField', null)
            ->assertJsonPath('data.attributeMapping', [['attributeValue' => 'student', 'template' => 'Students', 'templateExists' => true]]);

        $rows = $this->providerRows();
        $this->assertSame(['', '', '', '["student"]', '["Students"]'], [$rows['default_template'], $rows['filter'], $rows['attribute_field'], $rows['mappers'], $rows['templates']]);
    }

    public function test_invalid_patches(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig();
        $this->asAdmin();

        $this->patchProvider(['type' => 'keycloak'])->assertUnprocessable()->assertJsonPath('errors.type.0.code', 'immutable');
        $this->patchProvider(['type' => 'ldap', 'syncInterval' => 20])->assertOk();
        $this->patchProvider(['realm' => 'example'])->assertUnprocessable()
            ->assertJsonPath('errors.realm.0.code', 'conflictsWith')
            ->assertJsonPath('errors.realm.0.params', ['field' => 'type', 'value' => 'ldap']);
        $this->patchProvider(['periodicSync' => true, 'attributeField' => null])->assertUnprocessable()
            ->assertJsonPath('errors.attributeField.0.code', 'conflictsWith');
    }

    public function test_the_whole_save_rules_apply_to_the_merged_result(): void
    {
        $this->mailcow()->ldapConfig(['mappers' => '["staff"]', 'templates' => '["Deleted"]']);
        $this->asAdmin();

        $errors = (array) $this->patchProvider(['syncInterval' => 20])->assertUnprocessable()->json('errors');
        $this->assertSame(['attributeMapping.0.template'], array_keys($errors));
        $this->assertSame(['notFound', ['resource' => 'mailboxTemplate']], [$errors['attributeMapping.0.template'][0]['code'], $errors['attributeMapping.0.template'][0]['params']]);
        $this->patchProvider(['syncInterval' => 20, 'attributeMapping' => []])->assertOk();
    }

    public function test_an_empty_patch_is_invalid(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->patchProvider([])->assertUnprocessable()->assertJsonPath('errors.body.0.code', 'required');
    }

    public function test_patching_without_a_configuration_is_not_configured(): void
    {
        $this->asAdmin();

        $this->patchProvider(['syncInterval' => 20])->assertNotFound()
            ->assertJsonPath('type', '/api/v2/problems/identity-provider-not-configured');
        $this->assertSame('notFound', $this->providerAudit('identityProviderUpdate')[0]->failure_reason);
    }

    public function test_a_stale_if_match_changes_nothing(): void
    {
        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig();
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/identity-provider')->json('data.etag');

        $this->patchProvider(['syncInterval' => 20], ['If-Match' => $etag])->assertOk();
        $this->patchProvider(['syncInterval' => 25], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame('20', $this->providerRows()['sync_interval']);
        $refusals = array_values(array_filter($this->providerAudit('identityProviderUpdate'), fn ($r) => $r->outcome === 'failure'));
        $this->assertSame('versionOutdated', $refusals[0]->failure_reason);
    }
}
