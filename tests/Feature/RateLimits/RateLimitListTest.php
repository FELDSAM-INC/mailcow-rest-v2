<?php

namespace Tests\Feature\RateLimits;

use App\Auth\Role;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T070 (FR-051, AS-6.7): the rate limits set on existing domains, alias domains and
 * mailboxes in the caller's scope, classified from mailcow's `RL_VALUE` hash; fields of resources
 * and of objects that no longer exist are left out, as legacy lists only existing objects.
 */
class RateLimitListTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();

        foreach (['a.example', 'b.example', 'c.example', 'd.example'] as $domain) {
            $this->mailcow()->domain($domain);
        }

        $this->mailcow()->aliasDomain('alias-a.example', 'a.example');
        $this->mailcow()->aliasDomain('alias-c.example', 'c.example');

        foreach (['ann@a.example', 'bea@a.example', 'cid@b.example', 'dan@c.example'] as $mailbox) {
            $this->quarantineMailbox($mailbox);
        }

        $this->quarantineMailbox('room@a.example', kind: 'location');

        $limits = [
            'a.example' => '100 / 1h',
            'b.example' => '200 / 1d',
            'c.example' => '10 / 1w',
            'alias-a.example' => '5 / 1m',
            'ann@a.example' => '1 / 1s',
            'bea@a.example' => '2 / 1m',
            'cid@b.example' => '3 / 1h',
            'dan@c.example' => '4 / 1d',
            'room@a.example' => '9 / 1h',
            'gone@a.example' => '9 / 1h',
            'gone.example' => '9 / 1h',
            'D.EXAMPLE' => '9 / 1h',
        ];

        foreach ($limits as $object => $value) {
            $this->redis()->hSet('RL_VALUE', $object, $value);
        }
    }

    /**
     * @return list<string>
     */
    private function objects(string $query = ''): array
    {
        return array_column((array) $this->getJson('/api/v2/rate-limits'.($query === '' ? '' : '?'.$query))->assertOk()->json('data'), 'object');
    }

    public function test_an_administrator_lists_every_limit_of_existing_objects_by_object(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/rate-limits')->assertOk()->assertJsonPath('meta.total', 8);

        $this->assertSame(['a.example', 'alias-a.example', 'ann@a.example', 'b.example', 'bea@a.example', 'c.example', 'cid@b.example', 'dan@c.example'], array_column((array) $response->json('data'), 'object'));
        $entry = $response->json('data.0');
        $this->assertSame(['object' => 'a.example', 'kind' => 'domain', 'limit' => ['value' => 100, 'frame' => 'hour'], 'invalidStoredValue' => null], array_diff_key((array) $entry, ['etag' => true]));
        $this->assertSame((string) $this->getJson('/api/v2/domains/a.example/rate-limit')->json('data.etag'), $entry['etag']);
        $this->assertSame(['limit' => null, 'invalidStoredValue' => '10 / 1w'], array_intersect_key((array) $response->json('data.5'), ['limit' => true, 'invalidStoredValue' => true]));
        $this->assertSame(['effectiveLimit' => ['value' => 1, 'frame' => 'second'], 'effectiveSource' => 'mailbox'], array_intersect_key((array) $response->json('data.2'), ['effectiveLimit' => true, 'effectiveSource' => true]));
    }

    public function test_the_kind_filter_returns_exactly_the_mailbox_limits(): void
    {
        $this->asAdmin();

        $this->assertSame(['ann@a.example', 'bea@a.example', 'cid@b.example', 'dan@c.example'], $this->objects('kind=mailbox'));
        $this->assertSame(['alias-a.example'], $this->objects('kind=aliasDomain'));
        $this->assertSame(['a.example', 'b.example', 'c.example'], $this->objects('kind=domain'));
    }

    public function test_sorting_searching_and_paging(): void
    {
        $this->asAdmin();

        $this->assertSame(['dan@c.example', 'cid@b.example', 'c.example', 'bea@a.example'], $this->objects('order=desc&limit=4'));
        $this->assertSame(['alias-a.example', 'a.example', 'b.example', 'c.example', 'ann@a.example', 'bea@a.example', 'cid@b.example', 'dan@c.example'], $this->objects('sort=kind'));
        $this->assertSame(['ann@a.example', 'bea@a.example', 'cid@b.example', 'dan@c.example', 'a.example', 'b.example', 'c.example', 'alias-a.example'], $this->objects('sort=kind&order=desc'));
        $this->assertSame(['a.example', 'alias-a.example', 'ann@a.example', 'bea@a.example'], $this->objects('q=A.EXAMPLE'));
        $this->getJson('/api/v2/rate-limits?offset=6&limit=5')->assertOk()->assertJsonPath('meta', ['total' => 8, 'limit' => 5, 'offset' => 6])->assertJsonCount(2, 'data');
    }

    public function test_invalid_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/rate-limits?kind=resource')->assertStatus(422)->assertJsonPath('errors.kind.0.code', 'notInList');
        $this->getJson('/api/v2/rate-limits?sort=value')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
    }

    public function test_a_domain_administrator_gets_only_their_scope(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['a.example', 'c.example'], ['ratelimit' => 0], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->assertSame(['a.example', 'alias-a.example', 'ann@a.example', 'bea@a.example', 'c.example', 'dan@c.example'], $this->objects());
    }

    public function test_a_mailbox_user_is_refused(): void
    {
        $this->asQuarantineUser('ann@a.example');

        $this->getJson('/api/v2/rate-limits')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
