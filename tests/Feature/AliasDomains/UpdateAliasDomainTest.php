<?php

namespace Tests\Feature\AliasDomains;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T049 (FR-044, US4): updateAliasDomain.
 */
class UpdateAliasDomainTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('carl@example.com');
        $f->alias('a@example.net', 'carl@example.com');
        $f->alias('b@example.net', 'carl@example.com');
    }

    public function test_a_new_target_moves_the_aliases_and_rebuilds_the_groupware(): void
    {
        $this->asDomainAdmin(['example.org', 'example.com'], 'reseller', ['alias_domains' => 0]);

        $response = $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'example.com'], $this->csrf())->assertOk();

        $response->assertJsonPath('data.targetDomain', 'example.com');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame('example.com', DB::table('alias_domain')->where('alias_domain', 'example.net')->value('target_domain'));
        $this->assertStringContainsString('carl@example.net', (string) $this->viewRow('carl@example.com')?->ad_aliases);
        $this->assertSame(1, $this->memcached()->flushCount());
        $this->assertSame(['targetDomain' => ['old' => 'example.org', 'new' => 'example.com']], $this->auditOf('aliasDomainUpdate')[0]->details['changes']);
        $this->getJson('/api/v2/aliases?primaryDomain=example.com')->assertJsonCount(2, 'data');
    }

    public function test_the_new_target_alias_limit_must_fit_the_moved_aliases(): void
    {
        DB::table('domain')->where('domain', 'example.com')->update(['aliases' => 3]);
        $this->mailcow()->alias('c@example.com', 'carl@example.com');
        $this->mailcow()->alias('d@example.com', 'carl@example.com');
        $this->asAdmin();

        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'example.com'], $this->csrf())->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/domain-alias-limit-reached')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('limit', 3)
            ->assertJsonPath('requested', 2);
        $this->assertSame('example.org', DB::table('alias_domain')->where('alias_domain', 'example.net')->value('target_domain'));

        // An alias domain without aliases is never refused.
        DB::table('alias')->where('domain', 'example.net')->delete();
        DB::table('domain')->where('domain', 'example.com')->update(['aliases' => 0]);
        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'example.com'], $this->csrf())->assertOk();
    }

    public function test_a_local_part_shadowing_a_mailbox_of_the_new_target_is_allowed(): void
    {
        $this->mailcow()->alias('carl@example.net', 'someone@external.example');
        $this->asAdmin();

        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'example.com'], $this->csrf())->assertOk();
    }

    public function test_relay_and_alias_domain_targets_are_refused(): void
    {
        $f = $this->mailcow();
        $f->domain('relay.example', attributes: ['backupmx' => 1]);
        $f->aliasDomain('other.example', 'example.com');
        $this->asAdmin();

        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'relay.example'], $this->csrf())->assertStatus(422)
            ->assertJsonPath('errors.targetDomain.0.params.kind', 'relayDomain');
        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'other.example'], $this->csrf())->assertStatus(422)
            ->assertJsonPath('errors.targetDomain.0.params.kind', 'aliasDomain');
    }

    public function test_deactivation_keeps_the_domain_map_entry(): void
    {
        $this->mailcow()->redisHash('DOMAIN_MAP', 'example.net', '1');
        $this->asAdmin();

        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => false], $this->csrf())->assertOk()->assertJsonPath('data.active', false);

        $this->assertSame(0, (int) DB::table('alias_domain')->where('alias_domain', 'example.net')->value('active'));
        $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', 'example.net'));
    }

    public function test_the_representation_can_be_sent_back_and_read_only_members_are_immutable(): void
    {
        $this->asAdmin();
        $read = (array) $this->getJson('/api/v2/alias-domains/example.net')->json('data');

        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => false] + $read, $this->csrf())->assertOk();

        foreach (['name' => 'other.example', 'aliasCount' => 7, 'rateLimit' => ['value' => 1, 'frame' => 'day'], 'createdAt' => '2001-01-01T00:00:00Z', 'targetIsRelayDomain' => true] as $member => $value) {
            $this->patchMerge('/api/v2/alias-domains/example.net', [$member => $value], $this->csrf())->assertStatus(422)
                ->assertJsonPath("errors.{$member}.0.code", 'immutable');
        }

        $this->patchMerge('/api/v2/alias-domains/example.net', ['etag' => '"stale"', 'active' => true], $this->csrf())->assertOk();
        $this->patchMerge('/api/v2/alias-domains/example.net', ['targetDomain' => 'example.com', 'targetDomainUnicode' => 'example.com'], $this->csrf())->assertOk();
    }

    public function test_versions(): void
    {
        $this->asAdmin();
        $old = (string) $this->getJson('/api/v2/alias-domains/example.net')->headers->get('ETag');
        DB::table('alias_domain')->where('alias_domain', 'example.net')->update(['active' => 0]);

        $this->patchMerge('/api/v2/alias-domains/example.net', ['active' => true], ['If-Match' => $old] + $this->csrf())->assertStatus(412);
        $this->assertSame('versionOutdated', $this->auditOf('aliasDomainUpdate')[0]->failure_reason);
    }
}
