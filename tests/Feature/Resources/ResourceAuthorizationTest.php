<?php

namespace Tests\Feature\Resources;

use App\Auth\Role;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T075 (FR-001–FR-005, US7): the resource operations for every role and key type.
 */
class ResourceAuthorizationTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->resource('Room@example.org', 'Room');
        $f->resource('Room@foreign.example', 'Room');
        $f->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->mailbox('user@example.org');
    }

    public function test_read_only_keys_read_and_are_refused_on_writes(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller', 'readOnly'));

        $this->getJson('/api/v2/resources', $key)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/resources/Room@example.org', $key)->assertOk();
        $this->postJson('/api/v2/resources', ['description' => 'New', 'domain' => 'example.org', 'kind' => 'thing'], $key)->assertStatus(403);
        $this->patchMerge('/api/v2/resources/Room@example.org', ['active' => false], $key)->assertStatus(403);
        $this->deleteJson('/api/v2/resources/Room@example.org', [], $key)->assertStatus(403);

        foreach (['resourceCreation', 'resourceUpdate', 'resourceDeletion'] as $event) {
            $this->assertSame('permissionDenied', $this->auditOf($event)[0]->failure_reason);
        }
        $this->assertSame(['resource', 'Room@example.org', 'Room'], [$this->auditOf('resourceUpdate')[0]->target_type, $this->auditOf('resourceUpdate')[0]->target_id, $this->auditOf('resourceUpdate')[0]->target_name]);
        $this->assertSame('New@example.org', $this->auditOf('resourceCreation')[0]->target_id);
    }

    public function test_domain_administrators_reach_only_their_resources(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller'));

        $this->getJson('/api/v2/resources/Room@foreign.example', $key)->assertNotFound();
        $this->patchMerge('/api/v2/resources/Room@foreign.example', ['active' => false], $key)->assertNotFound();
        $this->deleteJson('/api/v2/resources/Room@foreign.example', [], $key)->assertNotFound();
        $this->postJson('/api/v2/resources', ['description' => 'New', 'domain' => 'example.org', 'kind' => 'thing'], $key)->assertCreated();
        $this->patchMerge('/api/v2/resources/Room@example.org', ['active' => false], $key)->assertOk();
        $this->deleteJson('/api/v2/resources/Room@example.org', [], $key)->assertNoContent();
        $this->assertSame('notFound', $this->auditOf('resourceDeletion')[0]->failure_reason);
    }

    public function test_mailbox_users_are_refused(): void
    {
        $this->signInAs('user@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/resources')->assertForbidden();
        $this->getJson('/api/v2/resources/Room@example.org')->assertForbidden();
        $this->postJson('/api/v2/resources', ['description' => 'New', 'domain' => 'example.org', 'kind' => 'thing'], $this->csrf())->assertForbidden();
        $this->patchMerge('/api/v2/resources/Room@example.org', ['active' => false], $this->csrf())->assertForbidden();
        $this->deleteJson('/api/v2/resources/Room@example.org', [], $this->csrf())->assertForbidden();
    }
}
