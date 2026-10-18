<?php

namespace Tests\Feature\MtaSts;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T075 (US4, FR-038, FR-058): the policies of the domains in the caller's scope, with
 * search, filters, sorts and pagination, and each entry's version tag equal to the single read's.
 */
class ListMtaStsPoliciesTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        foreach (['a.example', 'b.example', 'c.example', 'xn--bcher-kva.example', 'nopolicy.example'] as $domain) {
            $f->domain($domain);
        }
        $f->mtaSts('a.example', 'enforce', 'mail.example.org', 86400, 1, '20261018100000');
        $f->mtaSts('b.example', 'testing', 'mail.example.org', 900, 0, '20261018110000');
        $f->mtaSts('c.example', 'none', 'mail.example.org', 31557600, 1, '20261018090000');
        $f->mtaSts('xn--bcher-kva.example', 'enforce', 'mail.example.org', 60, 1, '20261018120000');
    }

    /**
     * @return TestResponse<Response>
     */
    private function list(string $query = ''): TestResponse
    {
        return $this->getJson('/api/v2/mta-sts-policies'.($query === '' ? '' : '?'.$query))->assertOk();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private static function domains(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'domain');
    }

    public function test_an_administrator_lists_every_policy_by_domain(): void
    {
        $this->asAdmin();

        $response = $this->list();

        $this->assertSame(['a.example', 'b.example', 'c.example', 'xn--bcher-kva.example'], self::domains($response));
        $response->assertJsonPath('meta', ['total' => 4, 'limit' => 25, 'offset' => 0]);
        $response->assertJsonPath('data.3.displayDomain', 'bücher.example');
        $response->assertJsonPath('data.1.active', false);
        $response->assertJsonPath('data.1.maxAge', 900);

        foreach ((array) $response->json('data') as $entry) {
            $this->getJson('/api/v2/mta-sts-policies/'.$entry['domain'])->assertHeader('ETag', $entry['etag']);
        }
    }

    public function test_search_filters_sorts_and_pagination(): void
    {
        $this->asAdmin();

        $this->assertSame(['xn--bcher-kva.example'], self::domains($this->list('q=B%C3%9CCH')));
        $this->assertSame(['xn--bcher-kva.example'], self::domains($this->list('q=XN--B')));
        $this->assertSame(['a.example', 'c.example', 'xn--bcher-kva.example'], self::domains($this->list('active=true')));
        $this->assertSame(['a.example', 'xn--bcher-kva.example'], self::domains($this->list('mode=enforce')));
        $this->assertSame(['xn--bcher-kva.example', 'b.example', 'a.example', 'c.example'], self::domains($this->list('sort=maxAge')));
        $this->assertSame(['c.example', 'a.example', 'b.example', 'xn--bcher-kva.example'], self::domains($this->list('sort=policyId')));
        $this->assertSame(['b.example', 'c.example', 'xn--bcher-kva.example', 'a.example'], self::domains($this->list('sort=mode&order=desc')));
        $this->assertSame(['b.example', 'c.example'], self::domains($this->list('limit=2&offset=1')));
    }

    public function test_an_invalid_filter_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/mta-sts-policies?mode=strict')->assertStatus(422)->assertJsonPath('errors.mode.0.code', 'notInList');
        $this->getJson('/api/v2/mta-sts-policies?active=1')->assertStatus(422)->assertJsonPath('errors.active.0.code', 'invalidType');
    }

    public function test_a_domain_administrator_lists_own_domains_only(): void
    {
        $this->asDomainAdmin(['b.example', 'nopolicy.example']);

        $this->assertSame(['b.example'], self::domains($this->list()));
    }

    public function test_a_row_changed_by_legacy_shows_on_the_next_read(): void
    {
        $this->asAdmin();
        $before = $this->list()->json('data.0.etag');

        DB::table('mta_sts')->where('domain', 'a.example')->update(['mode' => 'testing']);

        $response = $this->list();
        $response->assertJsonPath('data.0.mode', 'testing');
        $this->assertNotSame($before, $response->json('data.0.etag'));
    }
}
