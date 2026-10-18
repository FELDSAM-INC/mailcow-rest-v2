<?php

namespace Tests\Feature\Dkim;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T034 (US1, FR-007 to FR-010, research R8): one entry per domain, per alias domain and,
 * for administrators, per unused key, with filters, sorts, pagination and version tags, never a
 * private key.
 */
class ListDkimKeysTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        foreach (['a.example', 'b.example', 'c.example', 'd.example'] as $domain) {
            $f->domain($domain);
        }
        $f->aliasDomain('alias.example', 'a.example');
        $f->dkimKey('a.example', 'dkim', 2048);
        $f->dkimKey('alias.example', 's2', 1024);
        $f->dkimKey('b.example', 'dkim', 3072);
        $f->dkimKey('c.example', 's2', 1024);
        // The key of a deleted domain stays (002 FR-038).
        $f->dkimKey('gone.example', 'old', 1024);

        $this->asAdmin();
    }

    /**
     * @return TestResponse<Response>
     */
    private function list(string $query = ''): TestResponse
    {
        $response = $this->getJson('/api/v2/dkim-keys'.($query === '' ? '' : '?'.$query))->assertOk();
        $this->assertNoPemInResponse($response);

        return $response;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'name');
    }

    public function test_every_domain_alias_domain_and_unused_key_is_listed_by_name(): void
    {
        $response = $this->list();

        $this->assertSame(['a.example', 'alias.example', 'b.example', 'c.example', 'd.example', 'gone.example'], $this->names($response));
        $this->assertSame(['valid', 'valid', 'valid', 'valid', 'missing', 'unused'], array_column((array) $response->json('data'), 'state'));
        $response->assertJsonPath('meta', ['total' => 6, 'limit' => 25, 'offset' => 0]);
        $response->assertJsonPath('data.1.kind', 'aliasDomain');
        $response->assertJsonPath('data.1.targetDomain', 'a.example');
        $response->assertJsonPath('data.5.kind', 'none');
        $response->assertJsonPath('data.4.key', null);
        $response->assertJsonPath('data.4.etag', null);
        $response->assertJsonPath('data.0.key.keyLength', 2048);
        $response->assertJsonPath('data.0.key.record.name', 'dkim._domainkey.a.example');
        $response->assertJsonPath('data.0.key.complete', true);
        $response->assertJsonPath('data.0.key.missingParts', []);
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', (string) $response->json('data.0.etag'));
        $this->assertNotNull($response->json('data.5.etag'));
    }

    public function test_the_entry_etag_equals_the_single_read_etag(): void
    {
        $tag = $this->list()->json('data.0.etag');

        $this->getJson('/api/v2/dkim-keys/a.example')->assertOk()->assertHeader('ETag', $tag);
    }

    public function test_state_filters_match_any_of_the_given_states(): void
    {
        $this->assertSame(['d.example', 'gone.example'], $this->names($this->list('state=missing&state=unused')));
        $this->assertSame(['gone.example'], $this->names($this->list('state=unused')));
    }

    public function test_kind_selector_complete_and_domain_filters(): void
    {
        $this->mailcow()->domain('e.example');
        $this->mailcow()->partialDkimKey('e.example', ['publicKey' => DkimFixtures::RSA_1024_PUBLIC, 'selector' => 'dkim']);

        $this->assertSame(['alias.example'], $this->names($this->list('kind=aliasDomain')));
        $this->assertSame(['gone.example'], $this->names($this->list('kind=none')));
        $this->assertSame(['alias.example', 'c.example'], $this->names($this->list('selector=s2')));
        $this->assertSame([], $this->names($this->list('selector=S2')));
        $this->assertSame(['e.example'], $this->names($this->list('complete=false')));
        $this->assertSame(['a.example', 'alias.example', 'b.example', 'c.example', 'gone.example'], $this->names($this->list('complete=true')));
        $this->assertSame(['a.example', 'alias.example'], $this->names($this->list('domain=A.example')));
    }

    public function test_the_search_matches_the_ascii_and_the_readable_name(): void
    {
        $this->mailcow()->domain('xn--bcher-kva.example');

        $this->assertSame(['xn--bcher-kva.example'], $this->names($this->list('q=B%C3%9CCHER')));
        $this->assertSame(['xn--bcher-kva.example'], $this->names($this->list('q=xn--BCHER')));
        $this->assertSame(['b.example'], $this->names($this->list('q=B.EX')));
        $this->assertSame(['alias.example'], $this->names($this->list('q=ALIAS')));
        $this->list('q=b%C3%BCcher')->assertJsonPath('data.0.displayName', 'bücher.example');
    }

    public function test_sorts_in_both_orders_with_ties_by_name_and_keyless_entries_last(): void
    {
        $this->assertSame(['gone.example', 'd.example', 'c.example', 'b.example', 'alias.example', 'a.example'], $this->names($this->list('sort=name&order=desc')));
        $this->assertSame(['d.example', 'gone.example', 'a.example', 'alias.example', 'b.example', 'c.example'], $this->names($this->list('sort=state')));
        $this->assertSame(['a.example', 'b.example', 'gone.example', 'alias.example', 'c.example', 'd.example'], $this->names($this->list('sort=selector')));
        $this->assertSame(['alias.example', 'c.example', 'gone.example', 'a.example', 'b.example', 'd.example'], $this->names($this->list('sort=keyLength')));
        $this->assertSame(['d.example', 'b.example', 'a.example', 'gone.example', 'c.example', 'alias.example'], $this->names($this->list('sort=keyLength&order=desc')));
    }

    public function test_pagination(): void
    {
        $page = $this->list('limit=2&offset=2');

        $this->assertSame(['b.example', 'c.example'], $this->names($page));
        $page->assertJsonPath('meta', ['total' => 6, 'limit' => 2, 'offset' => 2]);
        $this->list('limit=1000')->assertJsonPath('meta.limit', 1000);
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->withoutRequestValidation();

        $this->getJson('/api/v2/dkim-keys?limit=1001')->assertStatus(422)
            ->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->assertSame('notInList', $this->getJson('/api/v2/dkim-keys?state=valid&state=bogus')->assertStatus(422)->json('errors')['state.1'][0]['code']);
        $this->getJson('/api/v2/dkim-keys?state=valid&state=valid&state=missing&state=unused')->assertStatus(422)
            ->assertJsonPath('errors.state.0.code', 'tooMany');
        $this->getJson('/api/v2/dkim-keys?complete=yes')->assertStatus(422)
            ->assertJsonPath('errors.complete.0.code', 'invalidType');
    }

    public function test_incomplete_and_selector_only_keys(): void
    {
        $this->mailcow()->domain('e.example');
        $this->mailcow()->domain('f.example');
        $this->mailcow()->partialDkimKey('e.example', ['publicKey' => DkimFixtures::RSA_1024_PUBLIC, 'selector' => 'dkim']);
        $this->mailcow()->partialDkimKey('f.example', ['selector' => 'sel']);

        $data = collect((array) $this->list('kind=domain')->json('data'))->keyBy('name');

        $this->assertSame('valid', $data['e.example']['state']);
        $this->assertFalse($data['e.example']['key']['complete']);
        $this->assertSame(['privateKey'], $data['e.example']['key']['missingParts']);
        $this->assertSame(1024, $data['e.example']['key']['keyLength']);
        $this->assertSame('sel', $data['f.example']['key']['selector']);
        $this->assertNull($data['f.example']['key']['publicKey']);
        $this->assertNull($data['f.example']['key']['keyLength']);
        $this->assertNull($data['f.example']['key']['record']);
        $this->assertSame(['publicKey', 'privateKey'], $data['f.example']['key']['missingParts']);
    }

    public function test_a_legacy_spelling_is_an_unused_key_and_a_left_over_private_key_creates_no_entry(): void
    {
        $this->mailcow()->legacyDkimKey('Example.ORG');
        $this->mailcow()->redisHash('DKIM_PRIV_KEYS', 'oldsel.copied.example', DkimFixtures::RSA_1024_PRIVATE);

        $data = collect((array) $this->list('state=unused')->json('data'))->keyBy('name');

        $this->assertSame(['Example.ORG', 'gone.example'], $data->keys()->all());
        $this->assertSame('Example.ORG', $data['Example.ORG']['displayName']);
    }

    public function test_an_unavailable_store_is_a_temporary_failure(): void
    {
        $this->storeUnavailable();

        $this->getJson('/api/v2/dkim-keys')->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertHeader('Retry-After');
    }
}
