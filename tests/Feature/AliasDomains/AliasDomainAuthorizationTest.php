<?php

namespace Tests\Feature\AliasDomains;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T051 (FR-001–FR-005, US4): the alias domain operations for every role and key type.
 */
class AliasDomainAuthorizationTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('foreign.net', 'foreign.example');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->domainAdmin('reseller', ['example.org'], ['alias_domains' => 1], scheme: 'SSHA256');
        $this->mailbox('user@example.org');
    }

    public function test_read_only_keys_read_but_never_write(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller', 'readOnly'));

        $this->getJson('/api/v2/alias-domains', $key)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/alias-domains/example.net', $key)->assertOk();
        $this->postJson('/api/v2/alias-domains', ['name' => 'new.example', 'targetDomain' => 'example.org'], $key)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => false], $key)->assertStatus(403);
        $this->deleteJson('/api/v2/alias-domains/example.net', [], $key)->assertStatus(403);

        $this->assertSame(2, DB::table('alias_domain')->count());
        foreach (['aliasDomainCreation', 'aliasDomainUpdate', 'aliasDomainDeletion'] as $event) {
            $this->assertSame('permissionDenied', $this->auditOf($event)[0]->failure_reason);
        }
    }

    public function test_domain_administrators_reach_only_their_alias_domains(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller'));

        $this->getJson('/api/v2/alias-domains/foreign.net', $key)->assertNotFound();
        $this->patchMerge('/api/v2/alias-domains/foreign.net', ['active' => false], $key)->assertNotFound();
        $this->deleteJson('/api/v2/alias-domains/foreign.net', [], $key)->assertNotFound();
        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'foreign.example'], $key)->assertStatus(422)->assertJsonPath('errors.targetDomain.0.code', 'notFound');
        $this->postJson('/api/v2/alias-domains', ['name' => 'new.example', 'targetDomain' => 'example.org', 'dkimSelector' => ''], $key)->assertCreated();
        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => false], $key)->assertOk();
        $this->deleteJson('/api/v2/alias-domains/example.net', [], $key)->assertNoContent();

        $this->assertSame(['notFound', 'validationFailed'], array_column(array_values(array_filter($this->auditOf('aliasDomainUpdate'), fn (object $r): bool => $r->outcome === 'failure')), 'failure_reason'));
    }

    public function test_mailbox_users_are_refused(): void
    {
        $this->signInAs('user@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/alias-domains')->assertForbidden();
        $this->getJson('/api/v2/alias-domains/example.net')->assertForbidden();
        $this->postJson('/api/v2/alias-domains', ['name' => 'new.example', 'targetDomain' => 'example.org'], $this->csrf())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => false], $this->csrf())->assertForbidden();
        $this->deleteJson('/api/v2/alias-domains/example.net', [], $this->csrf())->assertForbidden();
    }

    public function test_administrators_with_a_session_or_key(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->getJson('/api/v2/alias-domains')->assertOk()->assertJsonCount(2, 'data');
        $this->forgetCookies();

        $key = $this->keyHeader($this->apiKey('admin'));
        $this->patchMerge('/api/v2/alias-domains/foreign.net', ['active' => false], $key)->assertOk();
    }
}
