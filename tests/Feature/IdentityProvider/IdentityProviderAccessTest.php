<?php

namespace Tests\Feature\IdentityProvider;

use App\Auth\Role;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T038 (FR-001 to FR-003, FR-052, US1 AS-8): who may use the identity provider operations, and the
 * audit of every refusal of an authenticated caller.
 */
class IdentityProviderAccessTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxTemplate('Staff');
        $this->mailcow()->ldapConfig();
        FakeOidcProvider::install()->tokenIssued('client_credentials', 'service-token', null);
    }

    /**
     * The six operations, each with its event type (null for reads).
     *
     * @param  array<string, string>  $headers
     * @return array<string, array{TestResponse<Response>, string|null}>
     */
    private function callAll(array $headers): array
    {
        return [
            'capabilities' => [$this->getJson('/api/v2/capabilities/identity-provider', $headers), null],
            'read' => [$this->getJson('/api/v2/identity-provider', $headers), null],
            'save' => [$this->putJson('/api/v2/identity-provider', IdentityProviderFixtures::ldap(), $headers), 'identityProviderUpdate'],
            'patch' => [$this->patchMerge('/api/v2/identity-provider', ['syncInterval' => 20], $headers), 'identityProviderUpdate'],
            'test' => [$this->postJson('/api/v2/identity-provider/connection-tests', IdentityProviderFixtures::keycloak(), $headers), 'identityProviderConnectionTest'],
            'delete' => [$this->deleteJson('/api/v2/identity-provider', [], $headers), 'identityProviderDeletion'],
        ];
    }

    public function test_domain_administrators_and_mailbox_users_are_forbidden_and_audited(): void
    {
        $this->mailcow()->ensureDomain('example.org');

        foreach (['domainAdmin', 'mailboxUser'] as $role) {
            $this->forgetCookies();
            $role === 'domainAdmin' ? $this->asDomainAdmin(['example.org']) : $this->asMailboxUser();
            $csrf = $this->csrf();

            foreach ($this->callAll($csrf) as $name => [$response, $event]) {
                $response->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
            }
        }

        $refusals = $this->providerAudit();
        $this->assertCount(8, $refusals, 'four writes per role');
        $this->assertSame(['permissionDenied'], array_values(array_unique(array_column($refusals, 'failure_reason'))));
        $this->assertSame('ldap', $refusals[0]->target_name);
    }

    public function test_anonymous_callers_are_unauthenticated_and_not_audited(): void
    {
        foreach ($this->callAll(['X-CSRF-Token' => $this->csrfToken()]) as [$response]) {
            $response->assertUnauthorized();
        }

        $this->assertSame([], $this->allAudit());
    }

    public function test_a_restricted_session_must_complete_its_pending_actions(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', attributes: ['force_pw_update' => '1']);
        $this->signInAs('admin', Role::Admin);

        foreach ($this->callAll($this->csrf()) as [$response]) {
            $response->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        }

        $this->assertCount(4, $this->providerAudit());
    }

    public function test_a_csrf_failure_writes_no_audit_record(): void
    {
        $this->asAdmin();

        $this->putJson('/api/v2/identity-provider', IdentityProviderFixtures::ldap())->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->assertSame([], $this->allAudit());
    }

    public function test_keys(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);

        foreach ($this->callAll($this->keyHeader($this->apiKey('reseller'))) as [$response]) {
            $response->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        }

        foreach ($this->callAll($this->keyHeader($this->apiKey('admin', 'readOnly'))) as $name => [$response]) {
            in_array($name, ['capabilities', 'read'], true)
                ? $response->assertOk()
                : $response->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        }

        $readWrite = $this->keyHeader($this->apiKey('admin'));
        $this->getJson('/api/v2/identity-provider', $readWrite)->assertOk();
        $this->postJson('/api/v2/identity-provider/connection-tests', IdentityProviderFixtures::keycloak(), $readWrite)->assertOk();
        $this->patchMerge('/api/v2/identity-provider', ['syncInterval' => 20], $readWrite)->assertOk();
        $this->putJson('/api/v2/identity-provider', IdentityProviderFixtures::ldap(), $readWrite)->assertOk();
        $this->deleteJson('/api/v2/identity-provider', [], $readWrite)->assertNoContent();

        $refusals = array_values(array_filter($this->providerAudit(), fn ($r) => $r->outcome === 'failure'));
        $this->assertCount(8, $refusals, 'four writes of the domain administrator key and four of the read-only key');
        $this->assertSame('insufficient-key-scope', basename((string) $refusals[4]->details['problemType']));
    }
}
