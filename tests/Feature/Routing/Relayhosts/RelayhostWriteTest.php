<?php

namespace Tests\Feature\Routing\Relayhosts;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T029 (FR-009, FR-013, FR-014, FR-018–FR-021, FR-083, FR-085, FR-092, SC-009):
 * createRelayhost, updateRelayhost and deleteRelayhost.
 */
class RelayhostWriteTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_a_relayhost_is_created_active_with_its_credentials_stored_verbatim(): void
    {
        $response = $this->send('POST', '/api/v2/relayhosts', ['host' => '[smtp.example.net]:587', 'username' => 'acct', 'password' => 's3cret'])
            ->assertCreated();

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/relayhosts/'.$id)
            ->assertHeader('ETag', (string) $response->json('data.etag'))
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.passwordSet', true)
            ->assertJsonPath('data.username', 'acct');
        $this->assertStringNotContainsString('s3cret', (string) $response->getContent());

        $row = $this->relayhostRow($id);
        $this->assertSame(['[smtp.example.net]:587', 'acct', 's3cret', 1], [$row?->hostname, $row?->username, $row?->password, (int) $row?->active]);

        [$record] = $this->auditRecords('relayhostCreation', 'relayhost', (string) $id);
        $this->assertSame(['values' => ['host' => '[smtp.example.net]:587', 'username' => 'acct', 'password' => 'set', 'active' => true]], $record->details);
        $this->assertSame('[smtp.example.net]:587', $record->target_name);
    }

    public function test_a_password_with_a_colon_is_stored_without_escaping_and_active_false_is_honoured(): void
    {
        $id = (int) $this->send('POST', '/api/v2/relayhosts', ['host' => 'relay.example.net', 'username' => 'acct', 'password' => 'pa:ss', 'active' => false])
            ->assertCreated()->assertJsonPath('data.active', false)->json('data.id');

        $this->assertSame('pa:ss', $this->relayhostRow($id)?->password);
        $this->assertSame(0, (int) $this->relayhostRow($id)?->active);
    }

    public function test_hosts_are_normalised_and_invalid_hosts_refused(): void
    {
        $this->send('POST', '/api/v2/relayhosts', ['host' => '192.0.2.10:25'])->assertCreated()->assertJsonPath('data.host', '[192.0.2.10]:25');

        foreach (['smtp:relay.example.net', 'relay..example.net'] as $host) {
            $this->send('POST', '/api/v2/relayhosts', ['host' => $host])->assertStatus(422)
                ->assertJsonPath('errors.host.0.code', 'invalidFormat')
                ->assertJsonPath('errors.host.0.params.format', 'nextHop');
        }

        $this->assertSame(1, DB::table('relayhosts')->count());
    }

    public function test_credential_rules_at_creation(): void
    {
        $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'username' => 'user:name'])->assertStatus(422)
            ->assertJsonPath('errors.username.0.code', 'invalidCharacter');
        $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'password' => 'secret'])->assertStatus(422)
            ->assertJsonPath('errors.username.0.code', 'required')
            ->assertJsonPath('errors.username.0.params.because', 'password');

        $id = (int) $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'username' => ' acct ', 'password' => ' secret '])->assertCreated()->json('data.id');
        $this->assertSame(['acct', 'secret'], [$this->relayhostRow($id)?->username, $this->relayhostRow($id)?->password]);
    }

    public function test_several_invalid_fields_are_named_together(): void
    {
        $response = $this->send('POST', '/api/v2/relayhosts', ['host' => 'smtp:x', 'username' => 'a:b'])->assertStatus(422);

        $this->assertEqualsCanonicalizing(['host', 'username'], array_keys((array) $response->json('errors')));
    }

    public function test_an_invalid_password_type_is_refused(): void
    {
        $this->withoutRequestValidation();

        $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'username' => 'u', 'password' => 12])->assertStatus(422)
            ->assertJsonPath('errors.password.0.code', 'invalidType');
        $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'username' => 'u', 'password' => str_repeat('p', 256)])->assertStatus(422)
            ->assertJsonPath('errors.password.0.code', 'tooLong');
        $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'username' => str_repeat('u', 256)])->assertStatus(422)
            ->assertJsonPath('errors.username.0.code', 'tooLong');
        $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example', 'active' => 1])->assertStatus(422)
            ->assertJsonPath('errors.active.0.code', 'invalidType');
    }

    public function test_omitted_credentials_are_kept_and_explicit_empty_values_remove_them(): void
    {
        $id = $this->mailcow()->relayhost(null, 'r.example', 'acct', 's3cret');

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['active' => false])->assertOk()->assertJsonPath('data.passwordSet', true);
        $this->assertSame(['acct', 's3cret', 0], [$this->relayhostRow($id)?->username, $this->relayhostRow($id)?->password, (int) $this->relayhostRow($id)?->active]);

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => ''])->assertOk()->assertJsonPath('data.passwordSet', false);
        $this->assertSame(['acct', ''], [$this->relayhostRow($id)?->username, $this->relayhostRow($id)?->password]);

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => 'again'])->assertOk()->assertJsonPath('data.passwordSet', true);
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['username' => ''])->assertOk()->assertJsonPath('data.username', null)->assertJsonPath('data.passwordSet', false);
        $this->assertSame(['', ''], [$this->relayhostRow($id)?->username, $this->relayhostRow($id)?->password]);

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => 'lonely'])->assertStatus(422)
            ->assertJsonPath('errors.username.0.params.because', 'password');
    }

    public function test_legacy_escaped_credentials_stay_byte_identical_when_not_sent(): void
    {
        $id = $this->mailcow()->relayhost(null, 'r.example', 'u\:x', 'x\:y');

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['host' => 'other.example', 'active' => false])->assertOk();

        $this->assertSame(['u\:x', 'x\:y', 'other.example'], [$this->relayhostRow($id)?->username, $this->relayhostRow($id)?->password, $this->relayhostRow($id)?->hostname]);
    }

    public function test_read_only_members_are_accepted_unchanged_and_refused_otherwise(): void
    {
        $id = $this->mailcow()->relayhost(null, 'r.example', 'acct', 's3cret');
        $current = $this->getJson('/api/v2/relayhosts/'.$id)->json('data');

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, $current + ['active' => true])->assertOk();
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['id' => $id + 1])->assertStatus(422)->assertJsonPath('errors.id.0.code', 'immutable');
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['usage' => ['domains' => 4, 'mailboxes' => 0]])->assertStatus(422)->assertJsonPath('errors.usage.0.code', 'immutable');
    }

    public function test_invalid_unknown_members_and_empty_patches_are_refused(): void
    {
        $this->withoutRequestValidation();
        $id = $this->mailcow()->relayhost(null, 'r.example');

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['hostname' => 'x.example'])->assertStatus(422)->assertJsonPath('errors.hostname.0.code', 'immutable');
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, [])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
    }

    public function test_a_stale_if_match_is_refused_and_changes_nothing(): void
    {
        $id = $this->mailcow()->relayhost(null, 'r.example', 'acct', 's3cret');
        $etag = (string) $this->getJson('/api/v2/relayhosts/'.$id)->headers->get('ETag');

        $fresh = $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['host' => 'new.example'], ['If-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $fresh->headers->get('ETag'));

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['host' => 'stale.example'], ['If-Match' => $etag])->assertStatus(412);
        $this->send('DELETE', '/api/v2/relayhosts/'.$id, null, ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('new.example', $this->relayhostRow($id)?->hostname);
    }

    public function test_an_update_writes_one_audit_record_with_the_changes(): void
    {
        $id = $this->mailcow()->relayhost(null, 'r.example', 'acct', 's3cret');

        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['host' => 'n.example', 'password' => 'n3w', 'active' => false])->assertOk();
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => ''])->assertOk();

        $records = $this->auditRecords('relayhostUpdate', 'relayhost', (string) $id);
        $this->assertCount(2, $records);
        $this->assertSame(['host' => ['old' => 'r.example', 'new' => 'n.example'], 'password' => 'changed', 'active' => ['old' => true, 'new' => false]], $records[0]->details['changes']);
        $this->assertSame(['password' => 'removed'], $records[1]->details['changes']);
        $this->assertSame('n.example', $records[1]->target_name);
    }

    public function test_deleting_a_relayhost_clears_its_domain_and_mailbox_assignments_only(): void
    {
        $f = $this->mailcow();
        $id = $f->relayhost(null, 'r.example', 'acct', 's3cret');
        $other = $f->relayhost(null, 'keep.example');
        $f->domain('example.org', attributes: ['relayhost' => (string) $id, 'description' => 'Org']);
        $f->domain('example.com', attributes: ['relayhost' => (string) $other]);
        $this->mailbox('anna@example.com');
        $this->mailbox('bert@example.com');
        $f->mailboxRelayhost('anna@example.com', (string) $id);
        $f->mailboxRelayhost('bert@example.com', (string) $other);
        $domainBefore = (array) $this->domainRow('example.org');
        $attributesBefore = $this->mailboxAttributes('anna@example.com');

        $this->send('DELETE', '/api/v2/relayhosts/'.$id)->assertNoContent();

        $this->assertNull($this->relayhostRow($id));
        $this->assertSame('0', $this->domainRow('example.org')?->relayhost);
        $this->assertSame((string) $other, $this->domainRow('example.com')?->relayhost);
        $this->assertSame(array_merge($attributesBefore, ['relayhost' => '0']), $this->mailboxAttributes('anna@example.com'));
        $this->assertSame((string) $other, $this->mailboxAttributes('bert@example.com')['relayhost']);
        $this->assertSame(array_diff_key($domainBefore, ['relayhost' => 1, 'modified' => 1]), array_diff_key((array) $this->domainRow('example.org'), ['relayhost' => 1, 'modified' => 1]));

        [$record] = $this->auditRecords('relayhostDeletion', 'relayhost', (string) $id);
        $this->assertSame(['host' => 'r.example', 'clearedDomains' => ['example.org'], 'clearedMailboxes' => ['anna@example.com']], $record->details);
        $this->assertSame([], $this->dockerApi()->calls());
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_failure_inside_the_deletion_leaves_everything_unchanged(): void
    {
        $f = $this->mailcow();
        $id = $f->relayhost(null, 'r.example');
        $f->domain('example.org', attributes: ['relayhost' => (string) $id]);
        $this->mailbox('anna@example.org');
        $f->mailboxRelayhost('anna@example.org', (string) $id);
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, Principal|array|null $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): never
            {
                throw new RuntimeException('audit insert failed');
            }
        });

        $this->send('DELETE', '/api/v2/relayhosts/'.$id)->assertStatus(500);

        $this->assertNotNull($this->relayhostRow($id));
        $this->assertSame((string) $id, $this->domainRow('example.org')?->relayhost);
        $this->assertSame((string) $id, $this->mailboxAttributes('anna@example.org')['relayhost']);
    }

    public function test_unknown_relayhosts_are_not_found(): void
    {
        $this->send('PATCH', '/api/v2/relayhosts/999', ['active' => false])->assertNotFound();
        $this->send('DELETE', '/api/v2/relayhosts/999')->assertNotFound();
    }

    public function test_writes_call_no_container_service(): void
    {
        $id = (int) $this->send('POST', '/api/v2/relayhosts', ['host' => 'r.example'])->json('data.id');
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['active' => false])->assertOk();
        $this->send('DELETE', '/api/v2/relayhosts/'.$id)->assertNoContent();

        $this->assertSame([], $this->dockerApi()->calls());
        $this->assertSame(0, $this->memcached()->flushCount());
    }
}
