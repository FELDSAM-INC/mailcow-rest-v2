<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T065 (FR-036, FR-037, US6): copyAliasOverAliasDomains.
 */
class AliasCopyTest extends TestCase
{
    use AliasTestHelpers;

    private int $sales = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('lonely.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('example.com', 'example.org');
        $this->mailbox('anna@example.org');
        $this->sales = $f->alias('Sales@example.org', 'anna@example.org,bob@example.org', senderAllowed: 0, sogoVisible: 0, internal: 1, publicComment: 'not copied');
        $f->alias('sales@example.net', 'anna@example.org');
    }

    public function test_the_alias_is_copied_to_each_alias_domain_whose_address_is_free(): void
    {
        $this->asAdmin();

        $data = $this->postEmpty("/api/v2/aliases/{$this->sales}/alias-domain-copies", $this->csrf())->assertOk()->json('data');

        $this->assertSame($this->sales, $data['aliasId']);
        $this->assertSame('Sales@example.org', $data['address']);
        $this->assertSame(['example.com', 'example.net'], array_column($data['copies'], 'aliasDomain'));
        [$created, $skipped] = $data['copies'];
        $this->assertSame(['created', null, 'Sales@example.com', 'Sales@example.com'], [$created['outcome'], $created['reason'], $created['address'], $created['addressUnicode']]);
        $this->assertSame(['skipped', 'addressInUse', null], [$skipped['outcome'], $skipped['reason'], $skipped['aliasId']]);

        // No comments: NULL, as legacy's expand action stores them.
        $copy = $this->aliasRow((int) $created['aliasId']);
        $this->assertSame(['anna@example.org,bob@example.org', 1, 0, 0, 1, null, null], [$copy?->goto, (int) $copy?->active, (int) $copy?->sender_allowed, (int) $copy?->sogo_visible, (int) $copy?->internal, $copy?->public_comment, $copy?->private_comment]);

        $records = array_values(array_filter($this->auditOf('aliasCreation'), fn (object $r): bool => $r->outcome === 'success'));
        $this->assertCount(1, $records);
        $this->assertSame($this->sales, $records[0]->details['copiedFromAliasId']);
    }

    public function test_catch_alls_domains_without_alias_domains_and_aliases_in_alias_domains(): void
    {
        $f = $this->mailcow();
        $catchAll = $f->alias('@example.org', 'anna@example.org');
        $lonely = $f->alias('a@lonely.example', 'anna@example.org');
        $inAliasDomain = (int) DB::table('alias')->where('address', 'sales@example.net')->value('id');
        $this->asAdmin();

        $copies = $this->postEmpty("/api/v2/aliases/{$catchAll}/alias-domain-copies", $this->csrf())->assertOk()->json('data.copies');
        $this->assertSame(['@example.com', '@example.net'], array_column($copies, 'address'));
        $this->assertSame(['created', 'created'], array_column($copies, 'outcome'));

        $this->postEmpty("/api/v2/aliases/{$lonely}/alias-domain-copies", $this->csrf())->assertOk()->assertJsonPath('data.copies', []);

        $this->postEmpty("/api/v2/aliases/{$inAliasDomain}/alias-domain-copies", $this->csrf())->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/not-primary-domain-alias');
    }

    public function test_copies_stop_at_the_alias_limit_and_respect_the_external_rules(): void
    {
        DB::table('domain')->where('domain', 'example.org')->update(['aliases' => 3]);
        $this->asAdmin();

        $copies = $this->postEmpty("/api/v2/aliases/{$this->sales}/alias-domain-copies", $this->csrf())->json('data.copies');
        $this->assertSame(['created', 'skipped'], array_column($copies, 'outcome'));

        $f = $this->mailcow();
        $f->aliasDomain('example.info', 'example.org');
        $f->aliasDomain('example.biz', 'example.org');
        DB::table('domain')->where('domain', 'example.org')->update(['aliases' => 4]);
        $external = $f->alias('fwd@example.org', 'someone@gmail.example');
        DB::table('domain')->where('domain', 'example.org')->update(['aliases' => 5]);
        $copies = $this->postEmpty("/api/v2/aliases/{$external}/alias-domain-copies", $this->csrf())->json('data.copies');
        $this->assertSame(['example.biz', 'example.com', 'example.info', 'example.net'], array_column($copies, 'aliasDomain'));
        $this->assertSame(['created', 'skipped', 'skipped', 'skipped'], array_column($copies, 'outcome'));
        $this->assertSame([null, 'aliasLimitReached', 'aliasLimitReached', 'aliasLimitReached'], array_column($copies, 'reason'));

        DB::table('domain')->where('domain', 'example.org')->update(['aliases' => 400]);
        config(['restv2.vars_overrides.ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'true']);
        $copies = $this->postEmpty("/api/v2/aliases/{$external}/alias-domain-copies", $this->csrf())->json('data.copies');
        $this->assertSame(['addressInUse', 'destinationNotPermitted', 'destinationNotPermitted', 'destinationNotPermitted'], array_column($copies, 'reason'));
    }

    public function test_a_domain_administrator_without_the_external_flag_skips_external_copies(): void
    {
        $external = $this->mailcow()->alias('fwd@example.org', 'someone@gmail.example');
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_external_goto' => 0]);

        $copies = $this->postEmpty("/api/v2/aliases/{$external}/alias-domain-copies", $this->csrf())->json('data.copies');

        $this->assertSame(['destinationNotPermitted', 'destinationNotPermitted'], array_column($copies, 'reason'));
    }

    public function test_an_outdated_version_copies_nothing(): void
    {
        $this->asAdmin();

        $this->postEmpty("/api/v2/aliases/{$this->sales}/alias-domain-copies", ['If-Match' => '"outdated"'] + $this->csrf())->assertStatus(412);
        $this->assertNull($this->aliasRow('Sales@example.com'));
    }
}
