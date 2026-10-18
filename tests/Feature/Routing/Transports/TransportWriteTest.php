<?php

namespace Tests\Feature\Routing\Transports;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T046 (FR-034–FR-036, FR-039, FR-040, FR-085): createTransport, updateTransport and
 * deleteTransport.
 */
class TransportWriteTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    public function test_a_transport_is_created_inactive_and_can_be_activated(): void
    {
        $response = $this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => '[mx.partner.net]:25'])->assertCreated();
        $id = (int) $response->json('data.id');

        $response->assertHeader('Location', '/api/v2/transports/'.$id)
            ->assertHeader('ETag', (string) $response->json('data.etag'))
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.mxBased', false)
            ->assertJsonPath('meta.affectedTransports', []);
        $row = $this->transportRow($id);
        $this->assertSame(['example.com', '[mx.partner.net]:25', 0, 0, '', ''], [$row?->destination, $row?->nexthop, (int) $row?->active, (int) $row?->is_mx_based, $row?->username, $row?->password]);

        $this->send('PATCH', '/api/v2/transports/'.$id, ['active' => true])->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('meta.affectedTransports', []);
        $this->assertSame(1, (int) $this->transportRow($id)?->active);

        [$record] = $this->auditRecords('transportCreation', 'transport', (string) $id);
        $this->assertSame(['values' => ['destination' => 'example.com', 'nextHop' => '[mx.partner.net]:25', 'mxBased' => false, 'active' => false, 'username' => null, 'password' => null], 'affectedTransportIds' => []], $record->details);
        [$update] = $this->auditRecords('transportUpdate', 'transport', (string) $id);
        $this->assertSame(['changes' => ['active' => ['old' => false, 'new' => true]], 'affectedTransportIds' => []], $update->details);
    }

    public function test_destination_kinds(): void
    {
        foreach (['.example.com', '*', 'user@example.com'] as $destination) {
            $this->send('POST', '/api/v2/transports', ['destination' => $destination, 'nextHop' => 'hop.example'])->assertCreated()->assertJsonPath('data.destination', $destination);
        }

        foreach (['..example.com', 'exa mple.com', '.'] as $destination) {
            $this->send('POST', '/api/v2/transports', ['destination' => $destination, 'nextHop' => 'hop.example'])->assertStatus(422)
                ->assertJsonPath('errors.destination.0.code', 'invalidFormat')
                ->assertJsonPath('errors.destination.0.params.format', 'transportDestination');
        }

        $this->send('POST', '/api/v2/transports', ['destination' => ' Bücher.Example ', 'nextHop' => 'hop.example'])->assertCreated()->assertJsonPath('data.destination', 'xn--bcher-kva.example');
        $this->send('POST', '/api/v2/transports', ['destination' => 'User@Bücher.Example', 'nextHop' => 'hop.example'])->assertCreated()->assertJsonPath('data.destination', 'user@xn--bcher-kva.example');
    }

    public function test_mx_based_patterns_are_stored_as_given_and_checked_by_the_database(): void
    {
        $this->send('POST', '/api/v2/transports', ['destination' => '.*\.Google\.com', 'nextHop' => 'hop.example', 'mxBased' => true])->assertCreated()
            ->assertJsonPath('data.destination', '.*\.Google\.com')->assertJsonPath('data.mxBased', true);

        $this->send('POST', '/api/v2/transports', ['destination' => '(', 'nextHop' => 'hop.example', 'mxBased' => true])->assertStatus(422)
            ->assertJsonPath('errors.destination.0.code', 'invalidRegex');
    }

    public function test_destinations_are_unique_regardless_of_letter_case_and_kind(): void
    {
        $existing = $this->mailcow()->transport('example.com', 'hop.example');

        $this->send('POST', '/api/v2/transports', ['destination' => 'Example.com', 'nextHop' => 'other.example'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/routing-entry-exists')
            ->assertJsonPath('field', 'destination')
            ->assertJsonPath('conflictingId', (string) $existing);
        $this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => 'other.example', 'mxBased' => true])->assertStatus(409);

        $own = (int) $this->send('POST', '/api/v2/transports', ['destination' => 'example.net', 'nextHop' => 'hop.example'])->json('data.id');
        $this->send('PATCH', '/api/v2/transports/'.$own, ['destination' => 'EXAMPLE.net', 'active' => true])->assertOk();
        $this->send('PATCH', '/api/v2/transports/'.$own, ['destination' => 'example.com'])->assertStatus(409)->assertJsonPath('conflictingId', (string) $existing);
    }

    public function test_edits_normalise_next_hops_and_revalidate_destinations(): void
    {
        $id = $this->mailcow()->transport('example.com', 'hop.example');

        $this->send('PATCH', '/api/v2/transports/'.$id, ['nextHop' => '192.0.2.10'])->assertOk()->assertJsonPath('data.nextHop', '[192.0.2.10]');
        $this->send('PATCH', '/api/v2/transports/'.$id, ['mxBased' => true])->assertOk();
        $this->send('PATCH', '/api/v2/transports/'.$id, ['destination' => '(', 'mxBased' => true])->assertStatus(422)->assertJsonPath('errors.destination.0.code', 'invalidRegex');

        $pattern = $this->mailcow()->transport('(unclosed', 'hop2.example', '', '', 1);
        $this->send('PATCH', '/api/v2/transports/'.$pattern, ['mxBased' => false])->assertStatus(422)
            ->assertJsonPath('errors.destination.0.params.format', 'transportDestination');
    }

    public function test_read_only_members_a_stale_version_and_unknown_transports(): void
    {
        $id = $this->mailcow()->transport('example.com', 'hop.example');
        $current = $this->getJson('/api/v2/transports/'.$id)->json('data');
        $etag = (string) $current['etag'];

        $this->send('PATCH', '/api/v2/transports/'.$id, ['id' => $id + 1])->assertStatus(422)->assertJsonPath('errors.id.0.code', 'immutable');
        $this->send('PATCH', '/api/v2/transports/'.$id, ['nextHopSharedWith' => 5])->assertStatus(422)->assertJsonPath('errors.nextHopSharedWith.0.code', 'immutable');
        unset($current['username']);
        $this->send('PATCH', '/api/v2/transports/'.$id, ['active' => false] + $current)->assertOk();

        $this->send('PATCH', '/api/v2/transports/'.$id, ['active' => true], ['If-Match' => $etag])->assertStatus(412);
        $this->send('DELETE', '/api/v2/transports/'.$id, null, ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame(0, (int) $this->transportRow($id)?->active);

        $this->send('PATCH', '/api/v2/transports/999', ['active' => false])->assertNotFound();
        $this->send('DELETE', '/api/v2/transports/999')->assertNotFound();
    }

    public function test_deleting_one_transport_keeps_the_others_of_its_next_hop(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('example.com', '[hop.example]:25', 'u1', 'p1');
        $b = $f->transport('example.net', '[hop.example]:25', 'u1', 'p1');

        $this->send('DELETE', '/api/v2/transports/'.$a)->assertNoContent();

        $this->assertNull($this->transportRow($a));
        $this->assertSame(['u1', 'p1'], [$this->transportRow($b)?->username, $this->transportRow($b)?->password]);
        [$record] = $this->auditRecords('transportDeletion', 'transport', (string) $a);
        $this->assertSame(['destination' => 'example.com', 'nextHop' => '[hop.example]:25'], $record->details);
        $this->assertSame([], $this->dockerApi()->calls());
    }

    public function test_invalid_bodies_are_refused(): void
    {
        $this->withoutRequestValidation();

        $response = $this->send('POST', '/api/v2/transports', ['destination' => '', 'nextHop' => 'smtp:x', 'username' => 'a:b', 'active' => 'yes'])->assertStatus(422);
        $this->assertEqualsCanonicalizing(['destination', 'nextHop', 'username', 'active'], array_keys((array) $response->json('errors')));
        $this->send('POST', '/api/v2/transports', ['destination' => str_repeat('a', 256), 'nextHop' => 'h.example'])->assertStatus(422)->assertJsonPath('errors.destination.0.code', 'tooLong');
        $this->assertSame(0, DB::table('transports')->count());
    }
}
