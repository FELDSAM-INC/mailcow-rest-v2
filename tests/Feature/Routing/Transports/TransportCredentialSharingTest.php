<?php

namespace Tests\Feature\Routing\Transports;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use RuntimeException;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T047 (FR-038, FR-083, research R7): transports with the same next hop share one
 * username and password; creations inherit, edits propagate and moved transports adopt them.
 */
class TransportCredentialSharingTest extends TestCase
{
    use RoutingTestHelpers;

    private const H = '[mx.partner.net]:25';

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    /**
     * @return array{string, string}
     */
    private function credentials(int $id): array
    {
        $row = $this->transportRow($id);

        return [(string) $row?->username, (string) $row?->password];
    }

    public function test_a_creation_with_another_username_is_refused(): void
    {
        $a = $this->mailcow()->transport('a.example', self::H, 'u1', 'p1');

        $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => self::H, 'username' => 'u2'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/next-hop-credentials-differ')
            ->assertJsonPath('nextHop', self::H)
            ->assertJsonPath('transportIds', [$a]);
        $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => self::H, 'username' => ''])->assertStatus(409);
    }

    public function test_a_creation_without_credentials_inherits_them(): void
    {
        $this->mailcow()->transport('a.example', self::H, 'u1', 'p1');

        $response = $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => '[MX.partner.net]:25'])->assertCreated()
            ->assertJsonPath('data.username', 'u1')
            ->assertJsonPath('data.passwordSet', true)
            ->assertJsonPath('data.nextHopSharedWith', 1)
            ->assertJsonPath('meta.affectedTransports', []);

        $this->assertSame(['u1', 'p1'], $this->credentials((int) $response->json('data.id')));
    }

    public function test_a_creation_with_the_username_and_a_new_password_replaces_it_everywhere(): void
    {
        $a = $this->mailcow()->transport('a.example', self::H, 'u1', 'p1');
        $etag = $this->getJson('/api/v2/transports/'.$a)->headers->get('ETag');

        $response = $this->send('POST', '/api/v2/transports', ['destination' => 'b.example', 'nextHop' => self::H, 'username' => 'u1', 'password' => 'p2'])->assertCreated();

        $this->assertSame(['u1', 'p2'], $this->credentials($a));
        $this->assertSame(['u1', 'p2'], $this->credentials((int) $response->json('data.id')));
        $newEtag = $this->getJson('/api/v2/transports/'.$a)->headers->get('ETag');
        $this->assertNotSame($etag, $newEtag);
        $this->assertSame([['id' => $a, 'destination' => 'a.example', 'nextHop' => self::H, 'etag' => $newEtag]], $response->json('meta.affectedTransports'));
        [$record] = $this->auditRecords('transportCreation', 'transport', (string) $response->json('data.id'));
        $this->assertSame([$a], $record->details['affectedTransportIds']);
    }

    public function test_a_creation_with_only_a_password_keeps_the_username_and_replaces_every_password(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', self::H, 'u1', 'p1');
        $b = $f->transport('b.example', self::H, 'u1', 'p1');

        $response = $this->send('POST', '/api/v2/transports', ['destination' => 'c.example', 'nextHop' => self::H, 'password' => 'p3'])->assertCreated()
            ->assertJsonPath('data.username', 'u1');

        $this->assertSame(['u1', 'p3'], $this->credentials($a));
        $this->assertSame(['u1', 'p3'], $this->credentials($b));
        $this->assertSame([$a, $b], array_column((array) $response->json('meta.affectedTransports'), 'id'));
    }

    public function test_changing_a_password_changes_it_for_the_whole_next_hop(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', self::H, 'u1', 'p1');
        $b = $f->transport('b.example', self::H, 'u1', 'p1');
        $etagB = $this->getJson('/api/v2/transports/'.$b)->headers->get('ETag');

        $response = $this->send('PATCH', '/api/v2/transports/'.$a, ['password' => 'p9'])->assertOk();

        $this->assertSame(['u1', 'p9'], $this->credentials($b));
        $this->assertNotSame($etagB, $this->getJson('/api/v2/transports/'.$b)->headers->get('ETag'));
        $this->assertSame([$b], array_column((array) $response->json('meta.affectedTransports'), 'id'));
        [$record] = $this->auditRecords('transportUpdate', 'transport', (string) $a);
        $this->assertSame(['changes' => ['password' => 'changed'], 'affectedTransportIds' => [$b]], $record->details);
    }

    public function test_a_moved_transport_adopts_or_keeps_or_applies_credentials(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', self::H, 'u1', 'p1');
        $c = $f->transport('c.example', '[k.example]:25', 'own', 'secret');

        $this->send('PATCH', '/api/v2/transports/'.$c, ['nextHop' => self::H])->assertOk()->assertJsonPath('meta.affectedTransports', []);
        $this->assertSame(['u1', 'p1'], $this->credentials($c));
        $this->assertSame(['u1', 'p1'], $this->credentials($a));

        $d = $f->transport('d.example', '[k.example]:25', 'own', 'secret');
        $this->send('PATCH', '/api/v2/transports/'.$d, ['nextHop' => '[unused.example]:25'])->assertOk();
        $this->assertSame(['own', 'secret'], $this->credentials($d));

        $response = $this->send('PATCH', '/api/v2/transports/'.$d, ['nextHop' => self::H, 'username' => 'u1', 'password' => 'new'])->assertOk();
        $this->assertSame(['u1', 'new'], $this->credentials($a));
        $this->assertSame(['u1', 'new'], $this->credentials($c));
        $this->assertEqualsCanonicalizing([$a, $c], array_column((array) $response->json('meta.affectedTransports'), 'id'));
    }

    public function test_an_empty_username_clears_the_password_across_the_next_hop(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', self::H, 'u1', 'p1');
        $b = $f->transport('b.example', self::H, 'u1', 'p1');

        $this->send('PATCH', '/api/v2/transports/'.$a, ['username' => ''])->assertOk()->assertJsonPath('data.passwordSet', false);

        $this->assertSame(['', ''], $this->credentials($a));
        $this->assertSame(['', ''], $this->credentials($b));
        $this->send('PATCH', '/api/v2/transports/'.$a, ['password' => 'lonely'])->assertStatus(422)
            ->assertJsonPath('errors.username.0.params.because', 'password');
    }

    public function test_differing_stored_credentials_are_reported_as_stored_and_unified_by_the_next_change(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', self::H, 'u1', 'p1');
        $b = $f->transport('b.example', '[MX.partner.net]:25', 'u2', 'p2');

        $this->getJson('/api/v2/transports/'.$b)->assertJsonPath('data.username', 'u2');

        $this->send('PATCH', '/api/v2/transports/'.$a, ['username' => 'u3', 'password' => 'p3'])->assertOk();

        $this->assertSame(['u3', 'p3'], $this->credentials($b));
    }

    public function test_all_changes_of_a_request_are_stored_together(): void
    {
        $f = $this->mailcow();
        $a = $f->transport('a.example', self::H, 'u1', 'p1');
        $b = $f->transport('b.example', self::H, 'u1', 'p1');
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, Principal|array|null $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): never
            {
                throw new RuntimeException('audit insert failed');
            }
        });

        $this->send('PATCH', '/api/v2/transports/'.$a, ['password' => 'p2'])->assertStatus(500);

        $this->assertSame(['u1', 'p1'], $this->credentials($a));
        $this->assertSame(['u1', 'p1'], $this->credentials($b));
    }
}
