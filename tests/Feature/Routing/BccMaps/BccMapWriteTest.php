<?php

namespace Tests\Feature\Routing\BccMaps;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDO;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T068 (FR-049–FR-055, FR-084): createBccMap, updateBccMap and deleteBccMap.
 */
class BccMapWriteTest extends TestCase
{
    use RoutingTestHelpers;

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->domain('xn--bcher-kva.example');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('user@example.org');
        $this->mailbox('anna@xn--bcher-kva.example');
        $f->alias('shared@example.org', 'user@example.org,anna@xn--bcher-kva.example');
        $f->alias('direct@example.org', 'user@example.org');
        $f->alias('@example.org', 'user@example.org');
        $f->resource('room@example.org', 'Room');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->other?->query("SELECT RELEASE_LOCK('restv2:routing:bcc_maps')");
        $this->other = null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->send('POST', '/api/v2/bcc-maps', $body + ['type' => 'recipient', 'bccDestination' => 'archive@backup.net']);
    }

    public function test_a_domain_map_is_stored_as_legacy_stores_it(): void
    {
        $this->asAdmin();

        $response = $this->create(['localDestination' => 'example.org'])->assertCreated()
            ->assertJsonPath('data.localDestination', '@example.org')
            ->assertJsonPath('data.domain', 'example.org')
            ->assertJsonPath('data.type', 'recipient')
            ->assertJsonPath('data.active', true);
        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/bcc-maps/'.$id)->assertHeader('ETag', (string) $response->json('data.etag'));

        $row = DB::table('bcc_maps')->where('id', $id)->first();
        $this->assertSame(['@example.org', 'archive@backup.net', 'example.org', 'rcpt', 1], [$row?->local_dest, $row?->bcc_dest, $row?->domain, $row?->type, (int) $row?->active]);
        [$record] = $this->auditRecords('bccMapCreation', 'bccMap', (string) $id);
        $this->assertSame(['values' => ['localDestination' => '@example.org', 'type' => 'recipient', 'bccDestination' => 'archive@backup.net', 'domain' => 'example.org', 'active' => true]], $record->details);
    }

    public function test_accepted_local_destinations(): void
    {
        $this->asAdmin();

        foreach ([
            ['@example.net', '@example.net', 'example.net'],
            ['User@Example.org', 'user@example.org', 'example.org'],
            ['shared@example.org', 'shared@example.org', 'example.org'],
            ['direct@example.org', 'direct@example.org', 'example.org'],
            ['user@example.net', 'user@example.net', 'example.net'],
            ['anna@Bücher.Example', 'anna@xn--bcher-kva.example', 'xn--bcher-kva.example'],
            ['@bücher.example', '@xn--bcher-kva.example', 'xn--bcher-kva.example'],
        ] as [$input, $stored, $domain]) {
            $this->create(['localDestination' => $input, 'type' => 'sender'])->assertCreated()
                ->assertJsonPath('data.localDestination', $stored)
                ->assertJsonPath('data.domain', $domain);
        }

        $this->create(['localDestination' => 'direct@example.org', 'bccDestination' => 'direct@example.org'])->assertCreated();
    }

    public function test_refused_local_destinations_all_answer_not_found(): void
    {
        $this->asAdmin();

        foreach (['room@example.org', 'unknown@example.org', 'unknown.example', 'nobody@example.net', 'not a value'] as $input) {
            $this->create(['localDestination' => $input, 'type' => 'sender'])->assertStatus(422)->assertJsonPath('errors.localDestination.0.code', 'notFound');
        }
    }

    public function test_a_domain_administrator_with_the_flag_creates_and_without_it_is_refused(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1], 'withflag');
        $this->create(['localDestination' => 'user@example.org', 'type' => 'sender'])->assertCreated();

        $this->asRoutingDomainAdmin(['example.org'], [], 'noflag');
        $this->getJson('/api/v2/bcc-maps')->assertOk()->assertJsonPath('meta.total', 1);
        $this->create(['localDestination' => 'user@example.org'])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['bccMaps']);
    }

    public function test_foreign_and_unknown_destinations_give_the_same_error(): void
    {
        $this->mailbox('bob@foreign.example');
        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);

        $foreign = $this->create(['localDestination' => 'bob@foreign.example'])->assertStatus(422)->json('errors');
        $unknown = $this->create(['localDestination' => 'nobody@foreign.example'])->assertStatus(422)->json('errors');

        $this->assertSame($foreign, $unknown);
        $this->create(['localDestination' => 'foreign.example'])->assertStatus(422)->assertJsonPath('errors', $foreign);
    }

    public function test_one_map_per_local_destination_and_type(): void
    {
        $this->asAdmin();
        $first = (int) $this->create(['localDestination' => 'user@example.org'])->json('data.id');

        $this->create(['localDestination' => 'user@example.org'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/routing-entry-exists')
            ->assertJsonPath('conflictingId', (string) $first);
        $sender = (int) $this->create(['localDestination' => 'user@example.org', 'type' => 'sender'])->assertCreated()->json('data.id');

        $this->send('PATCH', '/api/v2/bcc-maps/'.$sender, ['type' => 'recipient'])->assertStatus(409)->assertJsonPath('field', 'type');
    }

    public function test_bcc_destination_rules(): void
    {
        $this->asAdmin();

        $this->create(['localDestination' => 'user@example.org', 'bccDestination' => 'a@example.net, b@example.net'])->assertStatus(422)
            ->assertJsonPath('errors.bccDestination.0.code', 'tooMany')->assertJsonPath('errors.bccDestination.0.params.max', 1);
        $this->create(['localDestination' => 'user@example.org', 'bccDestination' => 'not-an-address'])->assertStatus(422)
            ->assertJsonPath('errors.bccDestination.0.params.format', 'email');
        $this->create(['localDestination' => 'user@example.org', 'bccDestination' => 'Archive@Bücher.Example'])->assertCreated()
            ->assertJsonPath('data.bccDestination', 'Archive@xn--bcher-kva.example');
    }

    public function test_edits_and_read_only_members(): void
    {
        $this->asAdmin();
        $id = (int) $this->create(['localDestination' => 'user@example.org', 'type' => 'sender'])->json('data.id');
        $current = $this->getJson('/api/v2/bcc-maps/'.$id)->json('data');

        $this->send('PATCH', '/api/v2/bcc-maps/'.$id, ['bccDestination' => 'new@backup.net', 'active' => false, 'type' => 'recipient'])->assertOk()
            ->assertJsonPath('data.bccDestination', 'new@backup.net')->assertJsonPath('data.active', false)->assertJsonPath('data.type', 'recipient');
        $this->send('PATCH', '/api/v2/bcc-maps/'.$id, ['localDestination' => 'direct@example.org'])->assertStatus(422)->assertJsonPath('errors.localDestination.0.code', 'immutable');
        $this->send('PATCH', '/api/v2/bcc-maps/'.$id, ['localDestination' => 'user@example.org', 'domain' => 'example.org', 'id' => $id])->assertOk();
        $this->send('PATCH', '/api/v2/bcc-maps/'.$id, ['active' => true], ['If-Match' => (string) $current['etag']])->assertStatus(412);

        [$record] = $this->auditRecords('bccMapUpdate', 'bccMap', (string) $id);
        $this->assertSame(['type' => ['old' => 'sender', 'new' => 'recipient'], 'bccDestination' => ['old' => 'archive@backup.net', 'new' => 'new@backup.net'], 'active' => ['old' => true, 'new' => false]], $record->details['changes']);

        $this->send('DELETE', '/api/v2/bcc-maps/'.$id)->assertNoContent();
        $this->assertFalse(DB::table('bcc_maps')->where('id', $id)->exists());
        [$deletion] = $this->auditRecords('bccMapDeletion', 'bccMap', (string) $id);
        $this->assertSame(['localDestination' => 'user@example.org', 'type' => 'recipient'], $deletion->details);
    }

    public function test_an_administrator_edits_and_deletes_an_orphaned_map(): void
    {
        $this->asAdmin();
        $orphan = $this->mailcow()->bccMap('@gone.example', 'archive@backup.net', 'gone.example');

        $this->send('PATCH', '/api/v2/bcc-maps/'.$orphan, ['active' => false])->assertOk()->assertJsonPath('data.orphaned', true);
        $this->send('DELETE', '/api/v2/bcc-maps/'.$orphan)->assertNoContent();
    }

    public function test_a_held_lock_answers_temporary_failure(): void
    {
        $this->asAdmin();
        config(['restv2.routing.lock_timeout' => 1]);
        $config = config('database.connections.mysql');
        $this->other = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", (string) $config['username'], (string) $config['password']);
        $this->other->query("SELECT GET_LOCK('restv2:routing:bcc_maps', 0)");

        $this->create(['localDestination' => 'user@example.org'])->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        $this->assertSame(0, DB::table('bcc_maps')->count());
    }
}
