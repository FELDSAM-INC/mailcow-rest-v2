<?php

namespace Tests\Feature\Domains;

use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T041 (FR-066, US1 AS-9): listDomainTags returns the distinct tags of the domains in
 * the caller's scope with the number of domains carrying each.
 */
class ListDomainTagsTest extends TestCase
{
    use DomainTestHelpers;

    private function seedTags(): void
    {
        $f = $this->mailcow();
        foreach (['a.example', 'b.example', 'c.example', 'd.example'] as $d) {
            $f->domain($d);
        }
        $f->domainTags('a.example', ['gold', 'customer-a']);
        $f->domainTags('b.example', ['Gold', 'customer-b']);
        $f->domainTags('c.example', ['gold', 'secret-only-here']);
        $f->domainTags('d.example', ['bronze']);
        $f->domainAdmin('reseller', ['a.example', 'b.example', 'd.example']);
    }

    public function test_tags_in_scope_with_their_domain_counts(): void
    {
        $this->seedTags();
        $this->asDomainAdmin([], 'reseller');

        $response = $this->getJson('/api/v2/domain-tags')->assertOk();

        $this->assertSame([
            ['name' => 'bronze', 'domainCount' => 1],
            ['name' => 'customer-a', 'domainCount' => 1],
            ['name' => 'customer-b', 'domainCount' => 1],
            ['name' => 'Gold', 'domainCount' => 2],
        ], $response->json('data'));
        $this->assertSame(['total' => 4, 'limit' => 25, 'offset' => 0], $response->json('meta'));
    }

    public function test_administrators_see_every_tag(): void
    {
        $this->seedTags();
        $this->asAdmin();

        $data = collect((array) $this->getJson('/api/v2/domain-tags')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(3, $data['Gold']['domainCount']);
        $this->assertTrue($data->has('secret-only-here'));
    }

    public function test_search_sort_and_paging(): void
    {
        $this->seedTags();
        $this->asAdmin();

        $this->assertSame(['customer-a', 'customer-b'], array_column((array) $this->getJson('/api/v2/domain-tags?q=CUSTOMER')->assertOk()->json('data'), 'name'));

        $byCount = $this->getJson('/api/v2/domain-tags?sort=domainCount&order=desc&limit=2')->assertOk();
        $this->assertSame(['Gold', 'bronze'], array_column((array) $byCount->json('data'), 'name'));
        $this->assertSame(5, $byCount->json('meta.total'));

        $this->assertSame(['secret-only-here'], array_column((array) $this->getJson('/api/v2/domain-tags?order=desc&limit=1')->json('data'), 'name'));
        $this->assertSame(['customer-a'], array_column((array) $this->getJson('/api/v2/domain-tags?offset=1&limit=1')->json('data'), 'name'));
    }

    public function test_mailbox_users_are_forbidden(): void
    {
        $this->asMailboxUser();

        $this->getJson('/api/v2/domain-tags')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
