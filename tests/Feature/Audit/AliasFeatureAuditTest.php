<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T080 (FR-063, FR-064, SC-007, US8): every change and refusal of the feature is in the
 * audit log and can be listed by target with the generic filters.
 */
class AliasFeatureAuditTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->domainAdmin('reseller', ['example.org'], ['alias_external_goto' => 0, 'alias_domains' => 1, 'extend_sender_acl' => 1], scheme: 'SSHA256');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(string $type, string $id): array
    {
        return (array) $this->getJson('/api/v2/audit-events?targetType='.$type.'&targetId='.rawurlencode($id).'&order=asc')->assertOk()->json('data');
    }

    public function test_the_history_of_an_alias_shows_who_changed_what_and_the_refusals(): void
    {
        $id = $this->mailcow()->alias('sales@example.org', 'anna@example.org');
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->patchAlias($id, ['destinations' => ['bob@example.org']])->assertOk();
        $this->patchAlias($id, ['destinations' => ['someone@gmail.example']])->assertForbidden();
        $this->getJson('/api/v2/audit-events')->assertForbidden();

        $this->signInAs('admin', Role::Admin);
        $history = $this->history('alias', (string) $id);

        $this->assertCount(2, $history);
        $this->assertSame(['aliasUpdate', 'success', 'reseller', 'domainAdmin', '127.0.0.1'], [$history[0]['eventType'], $history[0]['outcome'], $history[0]['actor']['username'], $history[0]['actor']['role'], $history[0]['clientAddress']]);
        $this->assertSame(['old' => ['anna@example.org'], 'new' => ['bob@example.org']], $history[0]['details']['changes']['destinations']);
        $this->assertSame(['failure', 'attributeNotPermitted', '/api/v2/problems/attribute-not-permitted'], [$history[1]['outcome'], $history[1]['failureReason'], $history[1]['details']['problemType']]);
    }

    public function test_alias_domains_resources_and_sender_settings_have_their_histories(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->postJson('/api/v2/alias-domains', ['name' => 'bücher.example', 'targetDomain' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertCreated();
        $this->postJson('/api/v2/resources', ['description' => 'Room 1', 'domain' => 'example.org', 'kind' => 'location'], $this->csrf())->assertCreated();
        $this->patchMerge('/api/v2/mailboxes/anna@example.org/sender-settings', ['explicitPermissions' => ['bob@example.org']], $this->csrf())->assertOk();

        $this->assertSame(['aliasDomainCreation'], array_column($this->history('aliasDomain', 'xn--bcher-kva.example'), 'eventType'));
        $this->assertSame('bücher.example', $this->history('aliasDomain', 'xn--bcher-kva.example')[0]['target']['name']);
        $this->assertSame(['resourceCreation'], array_column($this->history('resource', 'Room1@example.org'), 'eventType'));
        $this->assertSame(['mailboxSenderSettingsUpdate'], array_column($this->history('mailboxSenderSettings', 'anna@example.org'), 'eventType'));
    }

    public function test_bulk_records_out_of_scope_ids_keys_and_unaudited_failures(): void
    {
        $own = $this->mailcow()->alias('own@example.org', 'anna@example.org');
        $foreign = $this->mailcow()->alias('x@foreign.example', 'anna@example.org');

        $this->signInAs('reseller', Role::DomainAdmin);
        $bulk = $this->postJson('/api/v2/aliases/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $own], ['id' => (string) $foreign]]], $this->csrf())->assertOk()->json('data.bulkRequestId');
        $this->deleteAlias(999999)->assertNotFound();
        $this->postJson('/api/v2/aliases', ['address' => 'a@example.org', 'destinations' => ['anna@example.org']])->assertForbidden();
        $this->forgetCookies();
        $this->deleteJson("/api/v2/aliases/{$own}")->assertUnauthorized();
        $this->deleteJson("/api/v2/aliases/{$own}", [], $this->keyHeader($this->apiKey('reseller', 'readOnly')))->assertForbidden();

        $this->signInAs('admin', Role::Admin);
        $this->assertSame($bulk, $this->history('alias', (string) $own)[0]['details']['bulkRequestId']);
        $foreignRecord = $this->history('alias', (string) $foreign)[0];
        $this->assertSame(['failure', 'notFound', $bulk], [$foreignRecord['outcome'], $foreignRecord['failureReason'], $foreignRecord['details']['bulkRequestId']]);
        $this->assertSame('notFound', $this->history('alias', '999999')[0]['failureReason']);
        $deletions = array_values(array_filter($this->history('alias', (string) $own), fn (array $r): bool => $r['eventType'] === 'aliasDeletion'));
        $this->assertCount(1, $deletions, 'Neither the CSRF failure nor the 401 is audited.');
        $this->assertSame('permissionDenied', $deletions[0]['failureReason']);
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'aliasCreation')->count());
    }

    public function test_every_write_of_the_feature_writes_exactly_one_record_per_object(): void
    {
        $this->signInAs('admin', Role::Admin);
        $before = DB::table('restv2_audit_events')->count();

        $a = (int) $this->createAlias(['address' => 'a@example.org', 'destinations' => ['anna@example.org']])->json('data.id');
        $b = (int) $this->createAlias(['address' => 'b@example.org', 'destinations' => ['anna@example.org']])->json('data.id');
        $this->patchAlias($a, ['active' => false])->assertOk();
        $this->postJson('/api/v2/aliases/bulk-actions', ['action' => 'activate', 'targets' => [['id' => (string) $a], ['id' => (string) $b]]], $this->csrf())->assertOk();
        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertCreated();
        $this->postEmpty("/api/v2/aliases/{$a}/alias-domain-copies", $this->csrf())->assertOk();
        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => false], $this->csrf())->assertOk();
        $this->postJson('/api/v2/resources', ['description' => 'Room', 'domain' => 'example.org', 'kind' => 'thing'], $this->csrf())->assertCreated();
        $this->patchMerge('/api/v2/resources/Room@example.org', ['active' => false], $this->csrf())->assertOk();
        $this->postJson('/api/v2/resources/bulk-actions', ['action' => 'delete', 'targets' => [['id' => 'Room@example.org']]], $this->csrf())->assertOk();
        $this->patchMerge('/api/v2/mailboxes/anna@example.org/sender-settings', ['externalSenderAddresses' => ['news@mailer.example']], $this->csrf())->assertOk();
        $this->deleteAlias($b)->assertNoContent();
        $this->deleteJson('/api/v2/alias-domains/example.net', [], $this->csrf())->assertNoContent();

        // 2 creations, 1 change, 2 bulk changes, 1 alias domain, 1 copy, 1 alias domain change,
        // 1 resource, 1 resource change, 1 bulk deletion, 1 sender settings change, 2 deletions.
        $this->assertSame(14, DB::table('restv2_audit_events')->count() - $before);
        $this->assertSame(0, DB::table('restv2_audit_events')->where('outcome', 'failure')->count());
    }
}
