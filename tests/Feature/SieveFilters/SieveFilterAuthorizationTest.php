<?php

namespace Tests\Feature\SieveFilters;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T105 (feature 005 FR-001 to FR-006, US9 AS-3, AS-4): who may read and change the Sieve filters of
 * which mailbox. Changes need the `filters` flag (administrators always have it, mailbox users
 * never); reads need none.
 */
class SieveFilterAuthorizationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private int $annaId;

    private int $foreignId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->annaId = $this->mailcow()->sieveFilterRow('anna@example.org', 'Anna', 'prefilter');
        $this->foreignId = $this->mailcow()->sieveFilterRow('dora@example.net', 'Dora', 'prefilter');
    }

    /**
     * List, read, create, change and delete of one mailbox's filters: the statuses.
     *
     * @param  array<string, string>  $headers
     * @return list<int>
     */
    private function operations(string $mailbox, int $id, array $headers = [], bool $session = true): array
    {
        $base = '/api/v2/mailboxes/'.$mailbox.'/sieve-filters';
        $csrf = fn (): array => $session ? $this->csrf() : [];

        return [
            $this->getJson($base, $headers)->status(),
            $this->getJson($base.'/'.$id, $headers)->status(),
            $this->postJson($base, ['description' => 'New', 'kind' => 'postfilter', 'script' => 'keep;', 'active' => false], $headers + $csrf())->status(),
            $this->patchMerge($base.'/'.$id, ['description' => 'Renamed'], $headers + $csrf())->status(),
            $this->deleteJson($base.'/'.$id, [], $headers + $csrf())->status(),
        ];
    }

    public function test_an_administrator_reaches_every_mailbox(): void
    {
        $this->asAdmin();

        $this->assertSame([200, 200, 201, 200, 204], $this->operations('dora@example.net', $this->foreignId));
    }

    public function test_a_domain_administrator_with_the_flag_reaches_only_the_own_domains(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['filters' => 1]);

        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', $this->annaId));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', $this->foreignId));
        $this->getJson('/api/v2/mailboxes/anna@example.org/sieve-filters/'.$this->foreignId)->assertNotFound();
    }

    public function test_a_domain_administrator_without_the_flag_may_only_read(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['filters' => 0]);

        $this->assertSame([200, 200, 403, 403, 403], $this->operations('anna@example.org', $this->annaId));
        $this->postJson('/api/v2/mailboxes/anna@example.org/sieve-filters', ['description' => 'New', 'kind' => 'postfilter', 'script' => 'keep;'], $this->csrf())
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['filters']);
        $this->assertSame('Anna', DB::table('sieve_filters')->where('id', $this->annaId)->value('script_desc'));

        $refusals = $this->auditRecords('sieveFilterCreation');
        $this->assertSame('failure', $refusals[0]->outcome);
        $this->assertSame(['filters'], $refusals[0]->details['requiredFlags']);
        $update = $this->auditRecords('sieveFilterUpdate')[0];
        $this->assertSame('Anna', $update->target_name);
        $this->assertSame('anna@example.org', $update->target_id);
        $this->assertSame($this->annaId, $update->details['sieveFilterId']);
        $this->assertArrayNotHasKey('sieveFilterId', $refusals[0]->details);
    }

    public function test_a_mailbox_user_reads_only_the_own_filters(): void
    {
        $this->asSelfServiceUser();

        $this->assertSame([200, 200, 403, 403, 403], $this->operations('anna@example.org', $this->annaId));
        $this->postJson('/api/v2/mailboxes/anna@example.org/sieve-filters', ['description' => 'New', 'kind' => 'postfilter', 'script' => 'keep;'], $this->csrf())
            ->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', $this->foreignId));
        $this->assertSame(2, DB::table('sieve_filters')->count());
    }

    public function test_keys_follow_their_owner_and_scope(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['filters' => 1]);

        $readOnly = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $this->assertSame([200, 200, 403, 403, 403], $this->operations('dora@example.net', $this->foreignId, $readOnly, false));
        $this->postJson('/api/v2/mailboxes/dora@example.net/sieve-filters', ['description' => 'New', 'kind' => 'postfilter', 'script' => 'keep;'], $readOnly)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $resellerKey = $this->keyHeader($this->apiKey('reseller', 'readWrite'));
        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', $this->annaId, $resellerKey, false));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', $this->foreignId, $resellerKey, false));
    }
}
