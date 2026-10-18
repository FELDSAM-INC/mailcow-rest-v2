<?php

namespace Tests\Feature\Routing\TlsPolicyMaps;

use App\Services\Routing\RoutingAudit;
use App\Services\Routing\TlsPolicyMapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\LegacyRoutingMaps;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T079 (FR-010, FR-065–FR-073, SC-002): the TLS policy map operations.
 */
class TlsPolicyMapTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function create(array $body): TestResponse
    {
        return $this->send('POST', '/api/v2/tls-policy-maps', $body + ['policy' => 'encrypt']);
    }

    public function test_an_entry_is_created_active_and_applied_by_the_mail_server(): void
    {
        $response = $this->create(['destination' => 'example.org', 'policy' => 'dane-only'])->assertCreated()
            ->assertJsonPath('data.destination', 'example.org')
            ->assertJsonPath('data.policy', 'dane-only')
            ->assertJsonPath('data.parameters', '')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.modifiedAt', null);
        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', '/api/v2/tls-policy-maps/'.$id)->assertHeader('ETag', (string) $response->json('data.etag'));

        $this->assertSame(['dane-only '], LegacyRoutingMaps::postfix('mysql_tls_policy_override_maps', 'example.org'));
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$id, ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertSame([], LegacyRoutingMaps::postfix('mysql_tls_policy_override_maps', 'example.org'));

        [$record] = $this->auditRecords('tlsPolicyMapCreation', 'tlsPolicyMap', (string) $id);
        $this->assertSame(['values' => ['destination' => 'example.org', 'policy' => 'dane-only', 'parameters' => '', 'active' => true]], $record->details);
    }

    /**
     * @return array<string, array{string, int, string|null}>
     */
    public static function destinations(): array
    {
        return [
            'subdomains' => ['.example.org', 201, '.example.org'],
            'bracketed with port' => ['[mail.example.org]:25', 201, '[mail.example.org]:25'],
            'bracketed IP' => ['[192.0.2.1]', 201, '[192.0.2.1]'],
            'Unicode' => ['Bücher.Example', 201, 'xn--bcher-kva.example'],
            'wildcard' => ['*', 422, null],
            'dot' => ['.', 422, null],
            'at' => ['@', 422, null],
            'port without brackets' => ['example.org:587', 422, null],
            'address' => ['user@example.org', 422, null],
        ];
    }

    #[DataProvider('destinations')]
    public function test_destination_forms(string $input, int $status, ?string $stored): void
    {
        $response = $this->create(['destination' => $input])->assertStatus($status);

        if ($stored !== null) {
            $response->assertJsonPath('data.destination', $stored);
        } else {
            $response->assertJsonPath('errors.destination.0.code', 'invalidFormat')->assertJsonPath('errors.destination.0.params.format', 'tlsPolicyDestination');
        }
    }

    public function test_policies_and_parameters(): void
    {
        $this->create(['destination' => 'a.example', 'policy' => 'strict'])->assertStatus(422)
            ->assertJsonPath('errors.policy.0.code', 'notInList')
            ->assertJsonPath('errors.policy.0.params.allowed', ['none', 'may', 'encrypt', 'dane', 'dane-only', 'fingerprint', 'verify', 'secure']);
        $this->create(['destination' => 'b.example', 'policy' => 'Encrypt'])->assertCreated()->assertJsonPath('data.policy', 'encrypt');
        $this->create(['destination' => 'c.example', 'parameters' => "protocols=!SSLv2   ciphers=medium\t"])->assertCreated()
            ->assertJsonPath('data.parameters', 'protocols=!SSLv2 ciphers=medium');
        $this->create(['destination' => 'd.example', 'parameters' => 'protocols'])->assertStatus(422)
            ->assertJsonPath('errors.parameters.0.params.format', 'tlsPolicyParameters');
        $this->create(['destination' => 'e.example', 'parameters' => '=x'])->assertStatus(422);
    }

    public function test_invalid_long_parameters_are_too_long(): void
    {
        $this->withoutRequestValidation();

        $this->create(['destination' => 'a.example', 'parameters' => str_repeat('a=b ', 70)])->assertStatus(422)->assertJsonPath('errors.parameters.0.code', 'tooLong');
    }

    public function test_destinations_are_unique_on_create_and_edit(): void
    {
        $first = (int) $this->create(['destination' => 'example.org'])->json('data.id');
        $second = (int) $this->create(['destination' => 'example.net'])->json('data.id');

        $this->create(['destination' => 'EXAMPLE.org'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/routing-entry-exists')->assertJsonPath('conflictingId', (string) $first)->assertJsonPath('field', 'destination');
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$second, ['destination' => 'EXAMPLE.org'])->assertStatus(409);
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$second, ['destination' => 'bücher.example'])->assertOk()->assertJsonPath('data.destination', 'xn--bcher-kva.example');
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$first, ['destination' => 'Example.org', 'policy' => 'NONE'])->assertOk()->assertJsonPath('data.policy', 'none');
    }

    public function test_a_duplicate_key_from_a_concurrent_insert_is_a_conflict(): void
    {
        $id = (int) $this->create(['destination' => 'example.org'])->json('data.id');
        $this->app->instance(TlsPolicyMapService::class, new class(app(RoutingAudit::class)) extends TlsPolicyMapService
        {
            protected function existing(string $destination, ?int $except): ?int
            {
                return null;
            }
        });

        $this->create(['destination' => 'example.org'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/routing-entry-exists');
        $this->assertSame(1, DB::table('tls_policy_override')->where('id', '>=', $id)->count());
    }

    public function test_list_filters_search_sorting_times_and_versions(): void
    {
        $f = $this->mailcow();
        $a = $f->tlsPolicyMap('a.example', 'dane', 'protocols=!SSLv2');
        $b = $f->tlsPolicyMap('b.example', 'encrypt', '', 0);
        $c = $f->tlsPolicyMap('c.example', 'secure', 'match=nexthop');
        DB::table('tls_policy_override')->where('id', $a)->update(['created' => '2026-07-01 12:00:00', 'modified' => null]);

        $this->assertSame([$a, $b], array_column((array) $this->getJson('/api/v2/tls-policy-maps?policy=dane&policy=encrypt')->json('data'), 'id'));
        $this->assertSame([$b], array_column((array) $this->getJson('/api/v2/tls-policy-maps?active=false')->json('data'), 'id'));
        $this->assertSame([$a], array_column((array) $this->getJson('/api/v2/tls-policy-maps?q=SSLV2')->json('data'), 'id'));
        $this->assertSame([$c], array_column((array) $this->getJson('/api/v2/tls-policy-maps?q=nexthop')->json('data'), 'id'));
        $this->assertSame([$a, $b, $c], array_column((array) $this->getJson('/api/v2/tls-policy-maps?sort=policy')->json('data'), 'id'));
        $this->assertSame([$c, $b, $a], array_column((array) $this->getJson('/api/v2/tls-policy-maps?sort=destination&order=desc')->json('data'), 'id'));
        $this->assertSame([$b, $a, $c], array_column((array) $this->getJson('/api/v2/tls-policy-maps?sort=active')->json('data'), 'id'));

        $single = $this->getJson('/api/v2/tls-policy-maps/'.$a)->assertOk()->assertJsonPath('data.createdAt', '2026-07-01T10:00:00Z')->assertJsonPath('data.modifiedAt', null);
        $this->assertSame($single->headers->get('ETag'), $single->json('data.etag'));
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$a, ['id' => $a + 1])->assertStatus(422)->assertJsonPath('errors.id.0.code', 'immutable');
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$a, ['policy' => 'may'])->assertOk();
        $this->send('PATCH', '/api/v2/tls-policy-maps/'.$a, ['policy' => 'none'], ['If-Match' => (string) $single->json('data.etag')])->assertStatus(412);
        $this->send('DELETE', '/api/v2/tls-policy-maps/'.$a)->assertNoContent();
        $this->getJson('/api/v2/tls-policy-maps/'.$a)->assertNotFound();
        [$record] = $this->auditRecords('tlsPolicyMapDeletion', 'tlsPolicyMap', (string) $a);
        $this->assertSame(['destination' => 'a.example'], $record->details);
    }
}
