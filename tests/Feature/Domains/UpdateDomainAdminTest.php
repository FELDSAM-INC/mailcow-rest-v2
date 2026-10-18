<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T059 (FR-030, FR-031, FR-033, FR-034, US3): administrators edit domains with JSON
 * Merge Patch; limits below usage, quota order, read-only members and the groupware side effects.
 */
class UpdateDomainAdminTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function patchDomain(array $patch, string $domain = 'example.org', array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/domains/'.rawurlencode($domain), $patch, $this->csrf() + $headers);
    }

    public function test_each_editable_attribute_changes_its_column(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $before = $this->getJson('/api/v2/domains/example.org')->headers->get('ETag');

        $response = $this->patchDomain([
            'description' => 'New description',
            'active' => false,
            'maxMailboxes' => 42,
            'maxAliases' => 43,
            'maxMailboxQuota' => 2048 * self::MIB,
            'domainQuota' => 4096 * self::MIB,
            'globalAddressList' => false,
            'tags' => ['b', 'A'],
        ])->assertOk();

        $row = $this->domainRow('example.org');
        $this->assertNotNull($row);
        $this->assertSame('New description', $row->description);
        $this->assertSame(0, (int) $row->active);
        $this->assertSame(42, (int) $row->mailboxes);
        $this->assertSame(43, (int) $row->aliases);
        $this->assertSame(2048, (int) $row->defquota, 'The legacy default is lowered to the new maximum.');
        $this->assertSame(2048, (int) $row->maxquota);
        $this->assertSame(4096, (int) $row->quota);
        $this->assertSame(0, (int) $row->gal);
        $this->assertSame(['A', 'b'], $this->tagsOf('example.org'));
        $this->assertSame(['A', 'b'], $response->json('data.tags'));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertNotSame($before, $response->headers->get('ETag'));
    }

    public function test_omitted_attributes_keep_their_values(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['description' => 'Keep me', 'aliases' => 77]);
        $this->mailcow()->domainTags('example.org', ['keep']);
        $this->asAdmin();

        $this->patchDomain(['maxMailboxes' => 11])->assertOk();

        $row = $this->domainRow('example.org');
        $this->assertNotNull($row);
        $this->assertSame('Keep me', $row->description);
        $this->assertSame(77, (int) $row->aliases);
        $this->assertSame(['keep'], $this->tagsOf('example.org'));
    }

    public function test_changed_limits_may_not_fall_below_usage(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org', attributes: ['maxquota' => 10240, 'quota' => 51200]);
        for ($i = 1; $i <= 8; $i++) {
            $f->mailbox("u{$i}@example.org", quota: $i <= 6 ? 5 * self::GIB : 0);
        }
        $f->aliasDomain('alias.example', 'example.org');
        $f->alias('a@example.org', 'u1@example.org');
        $f->alias('b@alias.example', 'u1@example.org');
        $this->asAdmin();

        $this->patchDomain(['maxMailboxes' => 5])->assertStatus(422)
            ->assertJsonPath('errors.maxMailboxes.0.code', 'belowInUse')
            ->assertJsonPath('errors.maxMailboxes.0.params', ['inUse' => 8]);
        $this->patchDomain(['domainQuota' => 20 * 1024 * self::MIB])->assertStatus(422)
            ->assertJsonPath('errors.domainQuota.0.code', 'belowInUse')
            ->assertJsonPath('errors.domainQuota.0.params', ['inUse' => 30 * self::GIB]);
        $this->patchDomain(['maxMailboxQuota' => 4096 * self::MIB])->assertStatus(422)
            ->assertJsonPath('errors.maxMailboxQuota.0.params', ['inUse' => 5 * self::GIB]);
        $this->patchDomain(['maxAliases' => 1])->assertStatus(422)
            ->assertJsonPath('errors.maxAliases.0.params', ['inUse' => 2]);

        // A limit that is already below usage does not block an edit that leaves it alone.
        DB::table('domain')->where('domain', 'example.org')->update(['mailboxes' => 1]);
        $this->patchDomain(['description' => 'still fine'])->assertOk();
        $this->assertSame(1, (int) $this->domainRow('example.org')?->mailboxes);
    }

    public function test_zero_values_are_applied_or_refused_never_ignored(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->patchDomain(['maxAliases' => 0])->assertOk()->assertJsonPath('data.maxAliases', 0);
        $this->assertSame(0, (int) $this->domainRow('example.org')?->aliases);

        $this->patchDomain(['domainQuota' => 1536 * self::MIB, 'maxMailboxQuota' => 1024 * self::MIB])->assertOk();
        $this->patchDomain(['maxMailboxQuota' => 2048 * self::MIB])->assertStatus(422)
            ->assertJsonPath('errors.maxMailboxQuota.0.params', ['field' => 'domainQuota', 'value' => 1536 * self::MIB]);
    }

    public function test_the_quota_order_is_checked_only_when_a_quota_changes(): void
    {
        // Legacy never checks the order on a domain administrator's edit, so a stored row can break
        // it (here: the dev seed's 5 GiB domain quota with the 10 GiB default maximum).
        $this->mailcow()->domain('example.org', attributes: ['maxquota' => 10240, 'quota' => 5120]);
        $this->asAdmin();

        $this->patchDomain(['description' => 'Unrelated'])->assertOk()->assertJsonPath('data.description', 'Unrelated');

        $whole = $this->getJson('/api/v2/domains/example.org')->json('data');
        $whole['globalAddressList'] = false;
        $this->patchDomain($whole)->assertOk()->assertJsonPath('data.globalAddressList', false);

        $this->patchDomain(['maxMailboxQuota' => 8192 * self::MIB])->assertStatus(422)
            ->assertJsonPath('errors.maxMailboxQuota.0.code', 'mustNotExceed')
            ->assertJsonPath('errors.maxMailboxQuota.0.params', ['field' => 'domainQuota', 'value' => 5120 * self::MIB]);
        $this->patchDomain(['domainQuota' => 6144 * self::MIB, 'description' => 'Other'])->assertStatus(422)
            ->assertJsonPath('errors.maxMailboxQuota.0.code', 'mustNotExceed')
            ->assertJsonPath('errors.maxMailboxQuota.0.params', ['field' => 'domainQuota', 'value' => 6144 * self::MIB]);

        $row = $this->domainRow('example.org');
        $this->assertSame([10240, 5120, 'Unrelated'], [(int) $row?->maxquota, (int) $row?->quota, $row?->description]);

        $this->patchDomain(['domainQuota' => 10240 * self::MIB])->assertOk();
        $this->assertSame(10240, (int) $this->domainRow('example.org')?->quota);
    }

    public function test_a_zero_quota_is_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->patchDomain(['maxMailboxQuota' => 0])->assertStatus(422)->assertJsonPath('errors.maxMailboxQuota.0.code', 'outOfRange');
    }

    public function test_relay_options_are_normalised_on_the_merged_values(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['backupmx' => 1, 'relay_all_recipients' => 1]);
        $this->asAdmin();

        $data = $this->patchDomain(['backupMx' => false])->assertOk()->json('data');
        $this->assertTrue($data['backupMx']);
        $this->assertTrue($data['relayAllRecipients']);

        $data = $this->patchDomain(['relayUnknownOnly' => true])->assertOk()->json('data');
        $this->assertTrue($data['relayUnknownOnly']);
    }

    public function test_an_empty_or_null_description_stores_the_domain_name(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['description' => 'Something']);
        $this->asAdmin();

        $this->patchDomain(['description' => ''])->assertOk()->assertJsonPath('data.description', 'example.org');
        $this->patchDomain(['description' => 'Again'])->assertOk();
        $this->patchDomain(['description' => null])->assertOk()->assertJsonPath('data.description', 'example.org');
    }

    public function test_read_only_members_are_accepted_unchanged_and_refused_changed(): void
    {
        $this->mailcow()->domain('xn--mnchen-3ya.de');
        $this->asAdmin();
        $current = $this->getJson('/api/v2/domains/xn--mnchen-3ya.de')->json('data');

        $whole = $current;
        $whole['maxMailboxes'] = 99;
        $this->patchDomain($whole, 'xn--mnchen-3ya.de')->assertOk()->assertJsonPath('data.maxMailboxes', 99);
        $this->patchDomain(['name' => 'münchen.de', 'maxMailboxes' => 98], 'xn--mnchen-3ya.de')->assertOk();

        $errors = $this->patchDomain([
            'name' => 'other.example',
            'createdAt' => '2000-01-01T00:00:00Z',
            'relayhost' => ['id' => 5, 'hostname' => 'x'],
            'rateLimit' => ['value' => 1, 'frame' => 'second'],
            'usage' => ['mailboxes' => 7],
        ], 'xn--mnchen-3ya.de')->assertStatus(422)->json('errors');

        foreach (['name', 'createdAt', 'relayhost', 'rateLimit', 'usage'] as $field) {
            $this->assertSame('immutable', $errors[$field][0]['code'], $field);
        }
    }

    public function test_an_empty_patch_is_invalid(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->patchDomain([])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
    }

    public function test_a_patch_that_changes_nothing_writes_nothing_but_is_audited(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $etag = $this->getJson('/api/v2/domains/example.org')->headers->get('ETag');

        $this->patchDomain(['maxMailboxes' => 10, 'description' => 'example.org'])->assertOk()->assertHeader('ETag', (string) $etag);

        $records = $this->auditOf('domainUpdate');
        $this->assertCount(1, $records);
        $this->assertSame('success', $records[0]->outcome);
        $this->assertSame([], $records[0]->details['changes']);
        $this->assertSame(0, $this->memcached()->flushCount());
        $this->assertNull($this->domainRow('example.org')?->modified);
    }

    public function test_a_change_rebuilds_the_groupware_directory_and_never_restarts_it(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->patchDomain(['globalAddressList' => false])->assertOk();

        $this->assertSame(1, $this->memcached()->flushCount());
        $this->dockerApi()->assertNothingCalled();

        config(['restv2.skip_sogo' => true]);
        $this->patchDomain(['globalAddressList' => true])->assertOk();
        $this->assertSame(1, $this->memcached()->flushCount()); // @phpstan-ignore method.alreadyNarrowedType
    }
}
