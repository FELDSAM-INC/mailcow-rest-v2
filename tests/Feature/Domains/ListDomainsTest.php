<?php

namespace Tests\Feature\Domains;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 002 T037 (FR-001, FR-002, FR-011–FR-015, US1): listDomains with paging, search,
 * filters and sorting, scoped to the caller.
 */
class ListDomainsTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @return list<string>
     */
    private function names(string $query = ''): array
    {
        $response = $this->getJson('/api/v2/domains'.$query)->assertOk();

        return array_column((array) $response->json('data'), 'name');
    }

    public function test_the_first_page_is_25_domains_by_name_with_the_total(): void
    {
        $names = [];
        for ($i = 1; $i <= 60; $i++) {
            $names[] = $name = sprintf('d%02d.example', $i);
            $this->mailcow()->domain($name);
        }
        $this->asAdmin();

        $response = $this->getJson('/api/v2/domains')->assertOk();

        $this->assertSame(array_slice($names, 0, 25), array_column((array) $response->json('data'), 'name'));
        $this->assertSame(['total' => 60, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $this->assertCount(60, $this->names('?limit=1000'));
        $this->assertSame(array_slice($names, 50, 10), $this->names('?offset=50'));
    }

    public function test_invalid_paging_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/domains?limit=1001')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/domains?offset=-1')->assertStatus(422)->assertJsonPath('errors.offset.0.code', 'outOfRange');
        $this->getJson('/api/v2/domains?sort=bogus')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->getJson('/api/v2/domains?active=yes')->assertStatus(422)->assertJsonPath('errors.active.0.code', 'invalidType');
    }

    private function seedForSorting(): void
    {
        $f = $this->mailcow();
        $f->domain('b.example', attributes: ['description' => 'Zulu', 'mailboxes' => 5, 'aliases' => 50, 'defquota' => 1024, 'maxquota' => 2048, 'quota' => 4096, 'backupmx' => 1, 'created' => '2026-03-01 10:00:00', 'modified' => '2026-04-01 10:00:00']);
        $f->domain('a.example', attributes: ['description' => 'Alpha', 'mailboxes' => 5, 'aliases' => 20, 'defquota' => 2048, 'maxquota' => 4096, 'quota' => 8192, 'created' => '2026-01-01 10:00:00']);
        $f->domain('c.example', 0, ['description' => 'Mike', 'mailboxes' => 1, 'aliases' => 90, 'defquota' => 512, 'maxquota' => 1024, 'quota' => 2048, 'created' => '2026-02-01 10:00:00', 'modified' => '2026-05-01 10:00:00']);
        $f->mailbox('x@b.example', quota: 3 * self::GIB);
        $f->mailbox('y@b.example', quota: self::GIB);
        $f->mailbox('x@c.example', quota: self::GIB);
        $f->alias('i@a.example', 'x@b.example');
        $f->alias('j@a.example', 'x@b.example');
        $f->alias('k@a.example', 'x@b.example');
        $f->alias('i@c.example', 'x@c.example');
        $f->quota2('x@c.example', 5000, 5);
        $f->quota2('x@b.example', 100, 1);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function sorts(): array
    {
        return [
            'name' => ['name', ['a.example', 'b.example', 'c.example']],
            'description' => ['description', ['a.example', 'c.example', 'b.example']],
            'active' => ['active', ['c.example', 'a.example', 'b.example']],
            'backupMx' => ['backupMx', ['a.example', 'c.example', 'b.example']],
            'mailboxes' => ['mailboxes', ['a.example', 'c.example', 'b.example']],
            'aliases' => ['aliases', ['b.example', 'c.example', 'a.example']],
            'quotaAllocated' => ['quotaAllocated', ['a.example', 'c.example', 'b.example']],
            'storageUsed' => ['storageUsed', ['a.example', 'b.example', 'c.example']],
            'maxMailboxes' => ['maxMailboxes', ['c.example', 'a.example', 'b.example']],
            'maxAliases' => ['maxAliases', ['a.example', 'b.example', 'c.example']],
            'maxMailboxQuota' => ['maxMailboxQuota', ['c.example', 'b.example', 'a.example']],
            'domainQuota' => ['domainQuota', ['c.example', 'b.example', 'a.example']],
            'createdAt' => ['createdAt', ['a.example', 'c.example', 'b.example']],
            'modifiedAt' => ['modifiedAt', ['a.example', 'b.example', 'c.example']],
        ];
    }

    /**
     * @param  list<string>  $ascending
     */
    #[DataProvider('sorts')]
    public function test_every_sort_field_in_both_orders_with_ties_by_name(string $sort, array $ascending): void
    {
        $this->seedForSorting();
        $this->asAdmin();

        $this->assertSame($ascending, $this->names("?sort={$sort}"), "{$sort} asc");

        // Ties stay in name order in both directions.
        $this->assertSame($this->expectedDescending($sort, $ascending), $this->names("?sort={$sort}&order=desc"), "{$sort} desc");
    }

    /**
     * The descending order: groups of equal values reversed, names ascending within a group.
     *
     * @param  list<string>  $ascending
     * @return list<string>
     */
    private function expectedDescending(string $sort, array $ascending): array
    {
        $ties = [
            'mailboxes' => [['a.example'], ['c.example'], ['b.example']],
            'active' => [['c.example'], ['a.example', 'b.example']],
            'backupMx' => [['a.example', 'c.example'], ['b.example']],
            'maxMailboxes' => [['c.example'], ['a.example', 'b.example']],
            'modifiedAt' => [['a.example'], ['b.example'], ['c.example']],
        ];
        $groups = $ties[$sort] ?? array_map(fn (string $n): array => [$n], $ascending);

        return array_merge(...array_reverse($groups));
    }

    public function test_the_search_covers_names_readable_names_descriptions_and_tags(): void
    {
        $f = $this->mailcow();
        $f->domain('one.example');
        $f->domainTags('one.example', ['customer-a']);
        $f->domain('two.example');
        $f->domainTags('two.example', ['Customer-B']);
        $f->domain('three.example', attributes: ['description' => 'Our CUSTOMER portal']);
        $f->domain('xn--mnchen-3ya.de');
        $f->domain('under_score.example');
        $f->domain('underscore.example');
        $f->domain('percent.example', attributes: ['description' => '100% off']);
        $this->asAdmin();

        $this->assertSame(['one.example', 'three.example', 'two.example'], $this->names('?q=customer'));
        $this->assertSame(['xn--mnchen-3ya.de'], $this->names('?q='.rawurlencode('MÜNCH')));
        $this->assertSame(['xn--mnchen-3ya.de'], $this->names('?q=mnchen'));
        $this->assertSame(['under_score.example'], $this->names('?q=r_s'));
        $this->assertSame(['percent.example'], $this->names('?q='.rawurlencode('0%')));
        $this->assertSame(['one.example'], $this->names('?q=one'));
    }

    public function test_filters(): void
    {
        $f = $this->mailcow();
        $f->domain('one.example');
        $f->domainTags('one.example', ['customer-a', 'gold']);
        $f->domain('two.example', 0, ['backupmx' => 1]);
        $f->domainTags('two.example', ['customer-a-old']);
        $f->domain('three.example', attributes: ['backupmx' => 1]);
        $f->domainTags('three.example', ['silver']);
        $this->asAdmin();

        $this->assertSame(['one.example'], $this->names('?tag=CUSTOMER-A'));
        $this->assertSame(['one.example', 'three.example'], $this->names('?tag=gold&tag=Silver'));
        $this->assertSame(['two.example'], $this->names('?active=false'));
        $this->assertSame(['one.example', 'three.example'], $this->names('?active=true'));
        $this->assertSame(['three.example', 'two.example'], $this->names('?backupMx=true'));
        $this->assertSame(['three.example'], $this->names('?backupMx=true&active=true'));
        $this->assertSame([], $this->names('?tag=customer'));
    }

    private function seedScope(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->mailcow()->domain("d{$i}.example", $i === 3 ? 0 : 1);
        }
        $this->mailcow()->domainAdmin('reseller', ['d1.example', 'd3.example']);
        $this->mailcow()->domainAdmin('other', ['d1.example', 'd5.example']);
        DB::table('domain_admins')->insert(['username' => 'reseller', 'domain' => 'd7.example', 'active' => 0]);
    }

    public function test_domain_administrators_see_exactly_their_domains(): void
    {
        $this->seedScope();
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->getJson('/api/v2/domains')->assertOk();

        $this->assertSame(['d1.example', 'd3.example'], array_column((array) $response->json('data'), 'name'));
        $this->assertSame(2, $response->json('meta.total'));
        foreach ((array) $response->json('data') as $entry) {
            $this->assertArrayNotHasKey('domainAdmins', $entry);
        }

        // Assignments changed in the legacy UI apply to the next request (FR-002).
        DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'd7.example')->update(['active' => 1]);
        DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'd1.example')->delete();
        $this->assertSame(['d3.example', 'd7.example'], $this->names());
    }

    public function test_administrators_get_the_sorted_domain_administrators(): void
    {
        $this->seedScope();
        $this->asAdmin();

        $entry = collect((array) $this->getJson('/api/v2/domains?limit=1000')->assertOk()->json('data'))->firstWhere('name', 'd1.example');

        $this->assertSame(['other', 'reseller'], $entry['domainAdmins']);
    }

    public function test_a_read_only_key_of_a_domain_administrator_lists_the_same_domains(): void
    {
        $this->seedScope();
        $key = $this->apiKey('reseller', 'readOnly');

        $response = $this->getJson('/api/v2/domains', $this->keyHeader($key))->assertOk();

        $this->assertSame(['d1.example', 'd3.example'], array_column((array) $response->json('data'), 'name'));
    }

    public function test_mailbox_users_restricted_sessions_and_anonymous_callers_are_refused(): void
    {
        $this->asMailboxUser();
        $this->getJson('/api/v2/domains')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->getJson('/api/v2/domains')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->forgetCookies();
        $this->getJson('/api/v2/domains')->assertStatus(401)->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }

    public function test_each_entry_carries_the_version_tag_of_the_single_read(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domainTags('a.example', ['x']);
        $this->mailcow()->domain('b.example', 0);
        $this->asAdmin();

        foreach ((array) $this->getJson('/api/v2/domains')->assertOk()->json('data') as $entry) {
            $single = $this->getJson('/api/v2/domains/'.$entry['name'])->assertOk();
            $this->assertSame($single->headers->get('ETag'), $entry['etag']);
        }
    }
}
