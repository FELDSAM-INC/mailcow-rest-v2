<?php

namespace Tests\Feature\IdentityProvider;

use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T035 (FR-015, FR-017, US1 AS-4, AS-7): type changes and deletion are refused while mailboxes
 * authenticate against another external source.
 */
class TypeChangeAndDeletionTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxTemplate('Staff');
    }

    public function test_a_type_change_and_a_deletion_are_refused_while_ldap_mailboxes_exist(): void
    {
        $this->mailcow()->ldapConfig();
        $this->externalMailboxes('ldap', 12);
        $before = $this->providerRows();
        $this->asAdmin();

        foreach ([$this->putProvider(IdentityProviderFixtures::keycloak()), $this->deleteProvider()] as $response) {
            $response->assertConflict()
                ->assertJsonPath('type', '/api/v2/problems/identity-provider-in-use')
                ->assertJsonPath('blockingMailboxes', 12)
                ->assertJsonPath('mailboxCounts', ['keycloak' => 0, 'genericOidc' => 0, 'ldap' => 12]);
        }

        $this->assertSame($before, $this->providerRows());
        $this->assertSame('conflict', $this->providerAudit('identityProviderUpdate')[0]->failure_reason);
        $deletion = $this->providerAudit('identityProviderDeletion');
        $this->assertSame('conflict', $deletion[0]->failure_reason);
        $this->assertSame(['keycloak' => 0, 'genericOidc' => 0, 'ldap' => 12], $deletion[0]->details['mailboxCounts']);
    }

    public function test_a_type_change_is_allowed_when_only_mailboxes_of_the_new_type_exist(): void
    {
        $this->mailcow()->ldapConfig();
        $this->externalMailboxes('keycloak', 2);
        $this->asAdmin();

        $this->putProvider(IdentityProviderFixtures::keycloak())->assertOk()->assertJsonPath('data.type', 'keycloak');

        $this->assertSame('ldap', $this->providerAudit('identityProviderUpdate')[0]->details['previousType']);
    }

    public function test_a_deletion_removes_every_row_and_single_sign_on_becomes_unavailable(): void
    {
        $this->mailcow()->genericOidcConfig(['access_token' => 'cached']);
        $this->mailcow()->mailboxWithDomain('local@example.org');
        $this->asAdmin();

        $this->getJson('/api/v2/public-config')->assertOk()->assertJsonPath('data.externalIdentityProvider.singleSignOnAvailable', true);

        $this->deleteProvider()->assertNoContent();

        $this->assertSame([], $this->providerRows());
        $this->getJson('/api/v2/public-config')->assertOk()
            ->assertJsonPath('data.externalIdentityProvider.configured', false)
            ->assertJsonPath('data.externalIdentityProvider.singleSignOnAvailable', false);

        $audit = $this->providerAudit('identityProviderDeletion');
        $this->assertCount(1, $audit);
        $this->assertSame(['success', 'genericOidc'], [$audit[0]->outcome, $audit[0]->details['type']]);
    }

    public function test_deleting_without_a_configuration_is_not_configured(): void
    {
        $this->asAdmin();

        $this->deleteProvider()->assertNotFound()->assertJsonPath('type', '/api/v2/problems/identity-provider-not-configured');
    }

    public function test_a_stale_if_match_refuses_the_deletion(): void
    {
        $this->mailcow()->ldapConfig();
        $this->asAdmin();

        $this->deleteProvider(['If-Match' => '"outdated"'])->assertStatus(412);

        $this->assertNotSame([], $this->providerRows());
        $this->assertSame('versionOutdated', $this->providerAudit('identityProviderDeletion')[0]->failure_reason);
    }
}
