<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T060 (FR-032, US3 AS-6, AS-7): domain administrators change the Global Address List
 * flag and the tags, and the description only with `domainDesc`; anything else is refused and
 * named.
 */
class UpdateDomainDomainAdminTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $patch
     * @return TestResponse<Response>
     */
    private function patchDomain(array $patch, string $domain = 'example.org'): TestResponse
    {
        return $this->patchMerge('/api/v2/domains/'.$domain, $patch, $this->csrf());
    }

    public function test_the_flag_and_tags_are_saved_and_the_description_needs_its_permission(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['description' => 'Original']);
        $this->asDomainAdmin(['example.org'], daAcl: ['domain_desc' => 0]);

        $this->patchDomain(['globalAddressList' => false, 'tags' => ['x']])->assertOk();
        $this->assertSame(0, (int) $this->domainRow('example.org')?->gal);
        $this->assertSame(['x'], $this->tagsOf('example.org'));

        $this->patchDomain(['description' => 'Changed'])->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('attributes', ['description']);
        $this->assertSame('Original', $this->domainRow('example.org')?->description);

        // The flag set in the legacy UI applies to the next request (FR-002).
        DB::table('da_acl')->where('username', 'reseller')->update(['domain_desc' => 1]);
        $this->patchDomain(['description' => 'Changed'])->assertOk();
        $this->assertSame('Changed', $this->domainRow('example.org')?->description);
    }

    public function test_other_attributes_are_refused_and_all_named(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->patchDomain(['maxMailboxes' => 99, 'active' => false, 'globalAddressList' => false])->assertStatus(403)
            ->assertJsonPath('attributes', ['active', 'maxMailboxes']);
        $this->assertSame(1, (int) $this->domainRow('example.org')?->gal);

        $whole = $this->getJson('/api/v2/domains/example.org')->json('data');
        $whole['globalAddressList'] = false;
        $this->patchDomain($whole)->assertOk();
        $this->assertSame(0, (int) $this->domainRow('example.org')?->gal);
    }

    public function test_stored_quotas_out_of_order_do_not_block_the_edit(): void
    {
        // Legacy's domain administrator branch never checks the quotas (the dev seed's example.org
        // had a 5 GiB domain quota and the 10 GiB default maximum mailbox quota).
        $this->mailcow()->domain('example.org', attributes: ['maxquota' => 10240, 'quota' => 5120]);
        $this->asDomainAdmin(['example.org'], daAcl: ['domain_desc' => 1]);

        $this->patchDomain(['globalAddressList' => false])->assertOk()->assertJsonPath('data.globalAddressList', false);
        $this->patchDomain(['description' => 'Changed', 'tags' => ['x']])->assertOk();

        $row = $this->domainRow('example.org');
        $this->assertSame([0, 'Changed', 10240, 5120], [(int) $row?->gal, $row?->description, (int) $row?->maxquota, (int) $row?->quota]);
    }

    public function test_scope_rules(): void
    {
        $this->mailcow()->domain('example.org', 0);
        $this->mailcow()->domain('foreign.example');
        $this->asDomainAdmin(['example.org']);

        $this->patchDomain(['globalAddressList' => false])->assertOk();
        $this->patchDomain(['globalAddressList' => false], 'foreign.example')->assertNotFound();

        DB::table('domain_admins')->where('username', 'reseller')->delete();
        $this->patchDomain(['globalAddressList' => true])->assertNotFound();
    }

    public function test_attribute_not_permitted_wins_over_immutable(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->patchDomain(['name' => 'other.example', 'maxMailboxes' => 1])->assertStatus(403)->assertJsonPath('attributes', ['maxMailboxes']);
        $this->patchDomain(['name' => 'other.example'])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'immutable');
    }

    public function test_keys_and_mailbox_users(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $key = $this->apiKey('reseller', 'readOnly');

        $this->patchMerge('/api/v2/domains/example.org', ['globalAddressList' => false], $this->keyHeader($key))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $write = $this->apiKey('reseller', 'readWrite');
        $this->patchMerge('/api/v2/domains/example.org', ['globalAddressList' => false], $this->keyHeader($write))->assertOk();

        $this->asMailboxUser('user@example.org');
        $this->patchDomain(['globalAddressList' => true])->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
