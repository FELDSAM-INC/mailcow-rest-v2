<?php

namespace Tests\Feature\Domains;

use App\Services\Domains\DomainVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 002 T016 (FR-041, research D7): version tags over the stored settings of domains,
 * footers and templates, never over usage, rate limits or domain administrators.
 */
class DomainVersionTest extends TestCase
{
    private function version(): DomainVersion
    {
        return $this->app->make(DomainVersion::class);
    }

    private function tag(string $domain = 'example.org'): string
    {
        return (string) $this->version()->ofDomain($domain);
    }

    public function test_the_tag_is_stable_and_strong(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainTags('example.org', ['b', 'A']);

        $this->assertSame($this->tag(), $this->tag());
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $this->tag());
        $this->assertNull($this->version()->ofDomain('missing.example'));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function storedColumns(): array
    {
        return [
            'description' => ['description', 'changed'],
            'aliases' => ['aliases', 1],
            'mailboxes' => ['mailboxes', 1],
            'defquota' => ['defquota', 1024],
            'maxquota' => ['maxquota', 2048],
            'quota' => ['quota', 4096],
            'relayhost' => ['relayhost', '3'],
            'backupmx' => ['backupmx', 1],
            'gal' => ['gal', 0],
            'relay_all_recipients' => ['relay_all_recipients', 1],
            'relay_unknown_only' => ['relay_unknown_only', 1],
            'active' => ['active', 0],
        ];
    }

    #[DataProvider('storedColumns')]
    public function test_the_tag_changes_with_every_stored_setting(string $column, mixed $value): void
    {
        $this->mailcow()->domain('example.org');
        $before = $this->tag();

        DB::table('domain')->where('domain', 'example.org')->update([$column => $value]);

        $this->assertNotSame($before, $this->tag());
    }

    public function test_the_tag_changes_with_the_tag_set(): void
    {
        $this->mailcow()->domain('example.org');
        $before = $this->tag();

        $this->mailcow()->domainTags('example.org', ['gold']);

        $this->assertNotSame($before, $this->tag());
    }

    public function test_the_tag_ignores_usage_rate_limits_and_domain_administrators(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->mailbox('a@example.org');
        $before = $this->tag();

        $this->mailcow()->quota2('a@example.org', 5000, 5);
        Redis::connection('mailcow')->hset('RL_VALUE', 'example.org', '10 / 1h');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->mailbox('b@example.org');
        $this->mailcow()->alias('info@example.org', 'a@example.org');

        $this->assertSame($before, $this->tag());
    }

    public function test_a_recreated_domain_with_the_same_values_gets_another_tag(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['created' => '2026-01-01 10:00:00']);
        $before = $this->tag();

        DB::table('domain')->where('domain', 'example.org')->delete();
        $this->mailcow()->domain('example.org', attributes: ['created' => '2026-02-01 10:00:00']);

        $this->assertNotSame($before, $this->tag());
    }

    public function test_an_absent_footer_and_a_stored_empty_footer_have_the_same_tag(): void
    {
        $this->mailcow()->domain('example.org');
        $absent = $this->version()->ofFooter('example.org');

        $this->mailcow()->domainFooter('example.org', null, '');
        $this->assertSame($absent, $this->version()->ofFooter('example.org'));

        DB::table('domain_wide_footer')->where('domain', 'example.org')->update(['skip_replies' => 1]);
        $this->assertNotSame($absent, $this->version()->ofFooter('example.org'));
    }

    public function test_template_tags_change_with_the_name_and_any_attribute(): void
    {
        $id = $this->mailcow()->defaultDomainTemplate();
        $row = fn (): object => DB::table('templates')->where('id', $id)->first();
        $before = $this->version()->forTemplate($row());

        DB::table('templates')->where('id', $id)->update(['template' => 'Renamed']);
        $renamed = $this->version()->forTemplate($row());
        $this->assertNotSame($before, $renamed);

        DB::table('templates')->where('id', $id)->update(['attributes' => DB::raw("JSON_SET(attributes, '$.key_size', 4096)")]);
        $this->assertNotSame($renamed, $this->version()->forTemplate($row()));
    }
}
