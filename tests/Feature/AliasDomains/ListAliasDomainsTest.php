<?php

namespace Tests\Feature\AliasDomains;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T047 (FR-038, FR-039, US4): listAliasDomains and getAliasDomain.
 */
class ListAliasDomainsTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('xn--bcher-kva.example', 'example.com', 0);
        $f->aliasDomain('alias.foreign.example', 'foreign.example');
        $f->alias('a@example.net', 'anna@example.org');
        $f->alias('b@example.net', 'anna@example.org');
        $f->redisHash('RL_VALUE', 'example.net', '500 / 1h');
    }

    /**
     * @return list<string>
     */
    private function names(string $query = ''): array
    {
        return array_column((array) $this->getJson('/api/v2/alias-domains'.$query)->assertOk()->json('data'), 'name');
    }

    public function test_the_representation_of_each_entry(): void
    {
        DB::table('domain')->where('domain', 'example.org')->update(['backupmx' => 1]);
        DB::table('alias_domain')->where('alias_domain', 'example.net')->update(['created' => '2026-07-01 12:00:00', 'modified' => null]);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/alias-domains')->assertOk();
        $this->assertSame(['total' => 3, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $entries = collect((array) $response->json('data'))->keyBy('name');

        $this->assertSame([
            'name' => 'example.net',
            'nameUnicode' => 'example.net',
            'targetDomain' => 'example.org',
            'targetDomainUnicode' => 'example.org',
            'active' => true,
            'targetIsRelayDomain' => true,
            'aliasCount' => 2,
            'rateLimit' => ['value' => 500, 'frame' => 'hour'],
            'createdAt' => '2026-07-01T10:00:00Z',
            'modifiedAt' => null,
            'etag' => $this->getJson('/api/v2/alias-domains/example.net')->headers->get('ETag'),
        ], $entries['example.net']);
        $this->assertSame('bücher.example', $entries['xn--bcher-kva.example']['nameUnicode']);
        $this->assertNull($entries['xn--bcher-kva.example']['rateLimit']);
        $this->assertSame(0, $entries['xn--bcher-kva.example']['aliasCount']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function queries(): array
    {
        return [
            'default' => ['', ['alias.foreign.example', 'example.net', 'xn--bcher-kva.example']],
            'name desc' => ['?order=desc', ['xn--bcher-kva.example', 'example.net', 'alias.foreign.example']],
            'target' => ['?sort=targetDomain', ['xn--bcher-kva.example', 'example.net', 'alias.foreign.example']],
            'active' => ['?sort=active&order=desc', ['alias.foreign.example', 'example.net', 'xn--bcher-kva.example']],
            'createdAt' => ['?sort=createdAt', ['alias.foreign.example', 'example.net', 'xn--bcher-kva.example']],
            'modifiedAt' => ['?sort=modifiedAt', ['alias.foreign.example', 'example.net', 'xn--bcher-kva.example']],
            'target filter' => ['?targetDomain=EXAMPLE.org&targetDomain=example.com', ['example.net', 'xn--bcher-kva.example']],
            'active filter' => ['?active=false', ['xn--bcher-kva.example']],
            'search name' => ['?q=NET', ['example.net']],
            'search target' => ['?q=foreign', ['alias.foreign.example']],
            'search unicode' => ['?q=%C3%BCcher', ['xn--bcher-kva.example']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('queries')]
    public function test_filters_sorting_and_search(string $query, array $expected): void
    {
        DB::table('alias_domain')->update(['created' => '2026-07-01 12:00:00', 'modified' => null]);
        $this->asAdmin();

        $this->assertSame($expected, $this->names($query));
    }

    public function test_a_domain_administrator_sees_only_the_alias_domains_of_their_domains(): void
    {
        $this->asDomainAdmin(['example.org', 'example.com']);

        $this->assertSame(['example.net', 'xn--bcher-kva.example'], $this->names());
        $this->getJson('/api/v2/alias-domains/alias.foreign.example')->assertNotFound();
        $this->getJson('/api/v2/alias-domains/EXAMPLE.NET')->assertOk()->assertJsonPath('data.name', 'example.net');
        $this->getJson('/api/v2/alias-domains/'.rawurlencode('bücher.example'))->assertOk()->assertJsonPath('data.name', 'xn--bcher-kva.example');
        $this->getJson('/api/v2/alias-domains/example.org')->assertNotFound();
    }

    public function test_an_unavailable_shared_store_answers_503(): void
    {
        $this->asAdmin();
        $this->breakSharedStore();

        $this->getJson('/api/v2/alias-domains')->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'sharedStore');
        $this->getJson('/api/v2/alias-domains/example.net')->assertStatus(503);
    }
}
