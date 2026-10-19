<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T068 (FR-036–FR-038, SC-003, US4 AS-3): after a deletion no row or shared-store
 * entry refers to the domain or its alias domains, apart from the kept DKIM key; a control domain
 * keeps everything.
 */
class DeleteDomainCleanupTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    private function seedDomain(string $d, string $a): void
    {
        $f = $this->mailcow();
        $f->domain($d);
        $f->domainTags($d, ['gold']);
        $f->aliasDomain($a, $d);
        $f->alias("info@{$d}", "x@{$d}");
        $f->alias("sales@{$a}", "x@{$d}");
        $f->senderAcl("someone@{$d}", "info@{$d}");
        $f->senderAcl('other@keep.example', "sales@{$a}");
        $f->senderAcl('other@keep.example', "@{$a}");
        $f->quota2("old@{$d}", 10, 1);
        $f->quota2replica("old@{$d}");
        $f->pushover("old@{$d}");
        $f->spamalias("tmp1@{$d}", "x@{$d}");
        $f->spamalias("tmp2@{$a}", "x@{$d}");
        $f->filterconf($d, 'whitelist_from', '*@friend.example');
        $f->filterconf($a, 'blacklist_from', '*@spam.example');
        $f->mtaSts($d);
        $f->bccMap("@{$d}", 'archive@keep.example', $d);
        $f->bccMap($d, 'archive@keep.example', $d);
        $f->bccMap("@{$a}", 'archive@keep.example', $d);
        $f->bccMap($a, 'archive@keep.example', $d);
        $f->domainFooter($d, '<p>footer</p>', 'footer');
        foreach ([$d, $a] as $name) {
            $this->redis()->hSet('DOMAIN_MAP', $name, '1');
            $this->redis()->hSet('RL_VALUE', $name, '10 / 1h');
        }
        $this->redis()->hSet('DKIM_SELECTORS', $d, 'dkim');
        $this->redis()->hSet('DKIM_PUB_KEYS', $d, 'MIIB');
        $this->redis()->hSet('DKIM_PRIV_KEYS', "dkim.{$d}", 'PRIV');
    }

    public function test_nothing_refers_to_the_deleted_names_and_the_control_domain_survives(): void
    {
        $this->seedDomain('d.example', 'a.example');
        $this->mailcow()->domain('keep.example');
        $this->seedDomain('e.example', 'ea.example');
        $before = $this->counts();
        $this->asAdmin();

        $this->deleteJson('/api/v2/domains/d.example', [], $this->csrf())->assertNoContent();

        foreach (['d.example', 'a.example'] as $name) {
            $this->assertSame([], $this->references($name), "rows still refer to {$name}");
            $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', $name));
            $this->assertFalse($this->redis()->hGet('RL_VALUE', $name));
        }

        $this->assertSame('dkim', $this->redis()->hGet('DKIM_SELECTORS', 'd.example'));
        $this->assertSame('MIIB', $this->redis()->hGet('DKIM_PUB_KEYS', 'd.example'));
        $this->assertSame('PRIV', $this->redis()->hGet('DKIM_PRIV_KEYS', 'dkim.d.example'));

        foreach (['e.example', 'ea.example'] as $name) {
            $this->assertNotSame([], $this->references($name));
            $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', $name));
        }
        // The control domain's rows are all still there; the sender permissions of keep.example
        // for the deleted alias domain went with it.
        $after = $this->counts();
        $this->assertSame($before['e'], $after['e']);
    }

    public function test_domain_administrators_left_without_a_domain_are_deleted(): void
    {
        $this->mailcow()->domain('d.example');
        $this->mailcow()->domain('e.example');
        $this->mailcow()->domainAdmin('only-d', ['d.example']);
        $this->mailcow()->domainAdmin('both', ['d.example', 'e.example']);
        $this->mailcow()->orphanedDomainAdmin('orphan');
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('only-d', 'readOnly');
        $this->asAdmin();

        $this->deleteJson('/api/v2/domains/d.example', [], $this->csrf())->assertNoContent();

        $this->assertSame(['admin', 'both'], DB::table('admin')->orderBy('username')->pluck('username')->all());
        $this->assertSame(['both'], DB::table('da_acl')->pluck('username')->all());
        $this->assertSame(['e.example'], DB::table('domain_admins')->pluck('domain')->all());

        $this->forgetCookies();
        // Feature 007 (FR-032, FR-034): the deleted account's keys are revoked.
        $this->getJson('/api/v2/domains', $this->keyHeader($key))->assertStatus(401)->assertJsonPath('type', '/api/v2/problems/api-key-revoked');
    }

    public function test_a_domain_created_again_starts_without_a_footer(): void
    {
        $this->mailcow()->domain('d.example');
        $this->mailcow()->domainFooter('d.example', '<p>old</p>', 'old');
        $this->asAdmin();

        $this->deleteJson('/api/v2/domains/d.example', [], $this->csrf())->assertNoContent();
        $this->postJson('/api/v2/domains', ['name' => 'd.example', 'dkimSelector' => ''], $this->csrf())->assertCreated();

        $this->assertSame(0, DB::table('domain_wide_footer')->count());
    }

    /**
     * Every row of the scanned tables that refers to a name.
     *
     * @return list<string>
     */
    private function references(string $name): array
    {
        $like = '%@'.$name;
        $found = [];
        $checks = [
            'domain' => fn () => DB::table('domain')->where('domain', $name)->count(),
            'domain_admins' => fn () => DB::table('domain_admins')->where('domain', $name)->count(),
            'alias' => fn () => DB::table('alias')->where('domain', $name)->orWhere('address', 'like', $like)->count(),
            'sender_acl' => fn () => DB::table('sender_acl')->where('logged_in_as', 'like', $like)->orWhere('send_as', 'like', $like)->orWhere('send_as', '@'.$name)->count(),
            'quota2' => fn () => DB::table('quota2')->where('username', 'like', $like)->count(),
            'quota2replica' => fn () => DB::table('quota2replica')->where('username', 'like', $like)->count(),
            'pushover' => fn () => DB::table('pushover')->where('username', 'like', $like)->count(),
            'spamalias' => fn () => DB::table('spamalias')->where('address', 'like', $like)->count(),
            'filterconf' => fn () => DB::table('filterconf')->where('object', $name)->count(),
            'mta_sts' => fn () => DB::table('mta_sts')->where('domain', $name)->count(),
            'bcc_maps' => fn () => DB::table('bcc_maps')->whereIn('local_dest', [$name, '@'.$name])->count(),
            'domain_wide_footer' => fn () => DB::table('domain_wide_footer')->where('domain', $name)->count(),
            'tags_domain' => fn () => DB::table('tags_domain')->where('domain', $name)->count(),
            'alias_domain' => fn () => DB::table('alias_domain')->where('alias_domain', $name)->orWhere('target_domain', $name)->count(),
        ];

        foreach ($checks as $table => $count) {
            if ($count() > 0) {
                $found[] = $table;
            }
        }

        return $found;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return ['e' => count($this->references('e.example')) + count($this->references('ea.example'))];
    }
}
