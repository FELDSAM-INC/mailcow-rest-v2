<?php

namespace Tests\Feature\Routing\RecipientMaps;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\Routing\LegacyRoutingMaps;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T083 (FR-010, FR-059–FR-064, FR-084, SC-002): the recipient map operations.
 */
class RecipientMapTest extends TestCase
{
    use RoutingTestHelpers;

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->other?->query("SELECT RELEASE_LOCK('restv2:routing:recipient_maps')");
        $this->other = null;
    }

    public function test_domain_and_address_maps_and_the_mail_server_lookup(): void
    {
        $response = $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'old.example', 'newDestination' => 'New.Example'])->assertCreated()
            ->assertJsonPath('data.originalDestination', '@old.example')
            ->assertJsonPath('data.newDestination', '@new.example')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.modifiedAt', null);
        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/recipient-maps/'.$id);

        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'info@example.org', 'newDestination' => 'sales@example.org'])->assertCreated();
        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'anna@example.org', 'newDestination' => 'someone@outside.example', 'active' => false])->assertCreated();

        $this->assertSame(['@new.example'], LegacyRoutingMaps::postfix('mysql_recipient_canonical_maps', '@old.example'));
        $this->assertSame(['sales@example.org'], LegacyRoutingMaps::postfix('mysql_recipient_canonical_maps', 'info@example.org'));
        $this->assertSame([], LegacyRoutingMaps::postfix('mysql_recipient_canonical_maps', 'anna@example.org'));

        [$record] = $this->auditRecords('recipientMapCreation', 'recipientMap', (string) $id);
        $this->assertSame(['values' => ['originalDestination' => '@old.example', 'newDestination' => '@new.example', 'active' => true]], $record->details);
    }

    public function test_invalid_destinations_conflicts_and_normalised_edits(): void
    {
        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'not a domain', 'newDestination' => 'x@example.org'])->assertStatus(422)
            ->assertJsonPath('errors.originalDestination.0.code', 'invalidFormat')
            ->assertJsonPath('errors.originalDestination.0.params.format', 'recipientMapDestination');
        $first = (int) $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => '@old.example', 'newDestination' => 'new.example'])->json('data.id');
        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'OLD.example', 'newDestination' => 'other.example'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/routing-entry-exists')
            ->assertJsonPath('field', 'originalDestination')
            ->assertJsonPath('conflictingId', (string) $first);

        $second = (int) $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'info@example.org', 'newDestination' => 'sales@example.org'])->json('data.id');
        $this->send('PATCH', '/api/v2/recipient-maps/'.$second, ['originalDestination' => 'Info@Example.org'])->assertOk()->assertJsonPath('data.originalDestination', 'info@example.org');
        $this->send('PATCH', '/api/v2/recipient-maps/'.$second, ['originalDestination' => 'old.example'])->assertStatus(409);
        $this->send('PATCH', '/api/v2/recipient-maps/'.$second, ['newDestination' => 'Bücher.Example'])->assertOk()->assertJsonPath('data.newDestination', '@xn--bcher-kva.example');
        $this->send('PATCH', '/api/v2/recipient-maps/'.$second, ['id' => $second + 1])->assertStatus(422)->assertJsonPath('errors.id.0.code', 'immutable');

        $domain = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 53);
        $this->assertSame(245, strlen($domain));
        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'abcdefgh@'.$domain, 'newDestination' => 'x@example.org'])->assertCreated();
    }

    public function test_invalid_destinations_longer_than_255_characters_as_stored_are_too_long(): void
    {
        $this->withoutRequestValidation();
        $domain = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 53);

        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'abcdefghij@'.$domain, 'newDestination' => 'x@example.org'])->assertStatus(422)
            ->assertJsonPath('errors.originalDestination.0.code', 'tooLong');
    }

    public function test_list_versions_times_deletion_and_a_held_lock(): void
    {
        $f = $this->mailcow();
        $a = $f->recipientMap('@a.example', '@x.example');
        $b = $f->recipientMap('b@example.org', 'c@example.org', 0);
        DB::table('recipient_maps')->where('id', $a)->update(['created' => '2026-07-01 12:00:00', 'modified' => null]);

        $this->assertSame([$b], array_column((array) $this->getJson('/api/v2/recipient-maps?active=false')->json('data'), 'id'));
        $this->assertSame([$a], array_column((array) $this->getJson('/api/v2/recipient-maps?q=X.EXAMPLE')->json('data'), 'id'));
        $this->assertSame([$b, $a], array_column((array) $this->getJson('/api/v2/recipient-maps?sort=originalDestination&order=desc')->json('data'), 'id'));
        $this->assertSame([$b, $a], array_column((array) $this->getJson('/api/v2/recipient-maps?sort=newDestination&order=desc')->json('data'), 'id'));
        $single = $this->getJson('/api/v2/recipient-maps/'.$a)->assertOk()->assertJsonPath('data.createdAt', '2026-07-01T10:00:00Z');
        $this->assertSame($single->headers->get('ETag'), $single->json('data.etag'));
        $this->send('PATCH', '/api/v2/recipient-maps/'.$a, ['active' => false])->assertOk();
        $this->assertNotNull($this->getJson('/api/v2/recipient-maps/'.$a)->json('data.modifiedAt'));
        $this->send('DELETE', '/api/v2/recipient-maps/'.$a, null, ['If-Match' => (string) $single->json('data.etag')])->assertStatus(412);
        $this->send('DELETE', '/api/v2/recipient-maps/'.$a)->assertNoContent();
        [$refused, $record] = $this->auditRecords('recipientMapDeletion', 'recipientMap', (string) $a);
        $this->assertSame('versionOutdated', $refused->failure_reason);
        $this->assertSame(['originalDestination' => '@a.example'], $record->details);

        config(['restv2.routing.lock_timeout' => 1]);
        $config = config('database.connections.mysql');
        $this->other = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", (string) $config['username'], (string) $config['password']);
        $this->other->query("SELECT GET_LOCK('restv2:routing:recipient_maps', 0)");
        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'z.example', 'newDestination' => 'y.example'])->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
