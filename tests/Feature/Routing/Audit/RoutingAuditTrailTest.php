<?php

namespace Tests\Feature\Routing\Audit;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T096 (FR-085, FR-086, SC-008): every routing change, test and refused attempt is found
 * in the audit log with the generic `targetType` and `targetId` filters, without any password, and
 * every request writes exactly one record per targeted entry.
 */
class RoutingAuditTrailTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $this->mailbox('anna@example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->domainAdmin('reseller', ['example.org'], ['domain_relayhost' => 0, 'mailbox_relayhost' => 0, 'bcc_maps' => 1], scheme: 'SSHA256');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trail(string $query): array
    {
        $this->signInAs('admin', Role::Admin);

        return (array) $this->getJson('/api/v2/audit-events?'.$query)->assertOk()->json('data');
    }

    public function test_a_password_change_is_listed_for_its_relayhost_without_the_password(): void
    {
        $this->signInAs('admin', Role::Admin);
        $id = $this->mailcow()->relayhost(null, 'r.example', 'acct', 'old-S3cret');
        $this->send('PATCH', '/api/v2/relayhosts/'.$id, ['password' => 'new-S3cret'])->assertOk();

        $events = $this->trail('targetType=relayhost&targetId='.$id);

        $this->assertCount(1, $events);
        $this->assertSame('relayhostUpdate', $events[0]['eventType']);
        $this->assertSame('admin', $events[0]['actor']['username']);
        $this->assertSame(['changes' => ['password' => 'changed']], $events[0]['details']);
        $this->assertNotEmpty($events[0]['clientAddress']);
        $this->assertStringNotContainsString('S3cret', (string) json_encode($events));
    }

    public function test_a_refused_assignment_is_listed_for_its_domain_and_a_mailbox_assignment_by_its_address(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->send('PUT', '/api/v2/domains/Example.ORG/relayhost-assignment', ['relayhostId' => null])->assertForbidden();

        $events = $this->trail('targetType=domainRelayhostAssignment&targetId=example.org');
        $this->assertCount(1, $events);
        $this->assertSame('failure', $events[0]['outcome']);
        $this->assertSame('permissionDenied', $events[0]['failureReason']);
        $this->assertSame(['domainRelayhost'], $events[0]['details']['requiredFlags']);

        $this->mailcow()->relayhost(5, 'r.example');
        $this->signInAs('admin', Role::Admin);
        $this->send('PUT', '/api/v2/mailboxes/anna@example.org/relayhost-assignment', ['relayhostId' => 5])->assertOk();
        $this->assertCount(1, $this->trail('targetType=mailboxRelayhostAssignment&targetId=anna@example.org'));
    }

    public function test_a_shared_password_change_names_the_affected_transports(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', '[hop.example]:25', 'u', 'p');
        $b = $f->transport('b.example', '[hop.example]:25', 'u', 'p');
        $c = $f->transport('c.example', '[hop.example]:25', 'u', 'p');
        $this->signInAs('admin', Role::Admin);
        $this->send('PATCH', '/api/v2/transports/'.$a, ['password' => 'q'])->assertOk();

        [$event] = $this->trail('targetType=transport&targetId='.$a);
        $this->assertSame([$b, $c], $event['details']['affectedTransportIds']);
    }

    public function test_tests_creations_bulk_items_and_bcc_changes_are_listed_once_per_target(): void
    {
        $this->signInAs('admin', Role::Admin);
        $relayhost = $this->mailcow()->relayhost(null, '[192.0.2.10]');
        $this->send('POST', '/api/v2/relayhosts/'.$relayhost.'/connection-test')->assertOk();
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.0/24'])->assertCreated();
        $other = $this->mailcow()->relayhost(null, 'other.example');
        $bulk = $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => (string) $relayhost], ['id' => (string) $other]]])->assertOk();
        $this->signInAs('reseller', Role::DomainAdmin);
        $map = (int) $this->send('POST', '/api/v2/bcc-maps', ['localDestination' => 'anna@example.org', 'type' => 'sender', 'bccDestination' => 'a@backup.net'])->assertCreated()->json('data.id');

        $this->assertSame(['relayhostUpdate', 'relayhostTest'], array_column($this->trail('targetType=relayhost&targetId='.$relayhost), 'eventType'));
        $this->assertCount(1, $this->trail('targetType=forwardingHost&targetId=192.0.2.0%2F24'));
        $this->assertCount(1, $this->trail('targetType=forwardingHost&q=192.0.2.0'));
        $this->assertSame($bulk->json('data.bulkRequestId'), $this->trail('targetType=relayhost&targetId='.$other)[0]['details']['bulkRequestId']);
        $this->assertCount(1, $this->trail('targetType=bccMap&targetId='.$map));
    }

    public function test_a_domain_administrator_may_not_read_the_audit_log(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->getJson('/api/v2/audit-events')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    public function test_every_request_writes_exactly_one_record_per_targeted_entry(): void
    {
        $this->signInAs('admin', Role::Admin);
        $count = fn (): int => DB::table('restv2_audit_events')->count();
        $scenarios = [
            fn () => $this->send('POST', '/api/v2/relayhosts', ['host' => 'one.example']),
            fn () => $this->send('POST', '/api/v2/relayhosts', ['host' => 'smtp:bad']),
            fn () => $this->send('POST', '/api/v2/transports', ['destination' => 'x.example', 'nextHop' => 'hop.example']),
            fn () => $this->send('POST', '/api/v2/transports', ['destination' => 'x.example', 'nextHop' => 'hop.example']),
            fn () => $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'old.example', 'newDestination' => 'new.example']),
            fn () => $this->send('POST', '/api/v2/tls-policy-maps', ['destination' => 'tls.example', 'policy' => 'may']),
            fn () => $this->send('POST', '/api/v2/bcc-maps', ['localDestination' => 'example.org', 'type' => 'recipient', 'bccDestination' => 'a@backup.net']),
            fn () => $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => null]),
            fn () => $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '198.51.100.7']),
        ];

        foreach ($scenarios as $i => $scenario) {
            $before = $count();
            $scenario();
            $this->assertSame($before + 1, $count(), "scenario {$i}");
        }

        $ids = DB::table('relayhosts')->pluck('id')->map(fn (mixed $id): array => ['id' => (string) $id])->all();
        $before = $count();
        $this->send('POST', '/api/v2/relayhosts/bulk-actions', ['action' => 'delete', 'targets' => [...$ids, ['id' => '999']]])->assertOk();
        $this->assertSame($before + count($ids) + 1, $count());
    }
}
