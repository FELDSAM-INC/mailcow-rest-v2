<?php

namespace Tests\Feature\Domains;

use App\Services\Domains\DomainUsage;
use App\Services\Domains\DomainUsageCalculator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 002 T015 (FR-008, research D5): usage figures computed as legacy
 * mailbox('get', 'domain_details') computes them, for a page of domains at once.
 */
class DomainUsageCalculatorTest extends TestCase
{
    private const GIB = 1073741824;

    private function usage(string $domain): DomainUsage
    {
        return $this->app->make(DomainUsageCalculator::class)->forDomains([$domain])[$domain];
    }

    public function test_mailboxes_aliases_and_alias_domains_are_counted_as_legacy_displays_them(): void
    {
        // AS-2 of US1.
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('alias.example', 'example.org');
        foreach (['a', 'b', 'c'] as $local) {
            $f->mailbox("{$local}@example.org");
        }
        $f->mailbox('room@example.org', kind: 'location');
        foreach (['info', 'sales', 'support'] as $local) {
            $f->alias("{$local}@example.org", 'a@example.org');
        }
        $f->alias('x@alias.example', 'a@example.org');
        $f->alias('y@alias.example', 'a@example.org');
        // Aliases equal to the mailboxes' own addresses (legacy writes them) are not counted.
        $f->alias('a@example.org', 'a@example.org');
        $f->alias('b@example.org', 'b@example.org');

        $usage = $this->usage('example.org');

        $this->assertSame(3, $usage->mailboxes);
        $this->assertSame(1, $usage->resources);
        $this->assertSame(5, $usage->aliases);
        $this->assertSame(1, $usage->aliasDomains);
        $this->assertSame(10 - 3, $usage->mailboxesLeft);
        $this->assertSame(400 - 5, $usage->aliasesLeft);
    }

    public function test_quota_figures(): void
    {
        // AS-8 of US1.
        $f = $this->mailcow();
        $f->domain('example.org', attributes: ['defquota' => 3072, 'maxquota' => 10240, 'quota' => 10240]);
        $f->mailbox('a@example.org', quota: self::GIB);
        $f->mailbox('b@example.org', quota: 2 * self::GIB);
        $f->mailbox('c@example.org', quota: 5 * self::GIB);
        $f->mailbox('unlimited@example.org', quota: 0);
        $f->mailbox('room@example.org', kind: 'location', quota: 50 * self::GIB);
        $f->mailbox('car@example.org', kind: 'thing');
        $f->quota2('a@example.org', 1000, 10);
        $f->quota2('c@example.org', 2000, 20);
        $f->quota2('room@example.org', 300, 3);

        $usage = $this->usage('example.org');

        $this->assertSame(4, $usage->mailboxes);
        $this->assertSame(2, $usage->resources);
        $this->assertSame(5 * self::GIB, $usage->largestMailboxQuota);
        $this->assertSame(8 * self::GIB, $usage->quotaAllocated);
        $this->assertSame(3300, $usage->storageUsed);
        $this->assertSame(33, $usage->messages);
        // min(10 GiB - 8 GiB, 10 GiB) = 2 GiB.
        $this->assertSame(2 * self::GIB, $usage->maxNewMailboxQuota);
        $this->assertArrayNotHasKey('defaultNewMailboxQuota', $usage->toArray());
    }

    public function test_left_figures_may_be_negative_and_new_quotas_never_are(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org', attributes: ['mailboxes' => 1, 'aliases' => 0, 'defquota' => 1024, 'maxquota' => 2048, 'quota' => 2048]);
        $f->mailbox('a@example.org', quota: 2 * self::GIB);
        $f->mailbox('b@example.org', quota: 2 * self::GIB);
        $f->alias('info@example.org', 'a@example.org');

        $usage = $this->usage('example.org');

        $this->assertSame(-1, $usage->mailboxesLeft);
        $this->assertSame(-1, $usage->aliasesLeft);
        $this->assertSame(0, $usage->maxNewMailboxQuota);
    }

    public function test_the_legacy_default_mailbox_quota_does_not_cap_the_new_quota(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['defquota' => 1024, 'maxquota' => 4096, 'quota' => 10240]);

        $usage = $this->usage('example.org');

        $this->assertSame(4 * self::GIB, $usage->maxNewMailboxQuota);
        $this->assertSame(0, $usage->storageUsed);
        $this->assertSame(0, $usage->largestMailboxQuota);
    }

    public function test_figures_of_a_page_take_at_most_six_queries(): void
    {
        $names = [];
        for ($i = 1; $i <= 25; $i++) {
            $names[] = $name = "d{$i}.example";
            $this->mailcow()->domain($name);
            $this->mailcow()->mailbox("user@{$name}", quota: self::GIB);
            $this->mailcow()->alias("info@{$name}", "user@{$name}");
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $usages = $this->app->make(DomainUsageCalculator::class)->forDomains($names);

        $this->assertLessThanOrEqual(6, $queries);
        $this->assertCount(25, $usages);
        $this->assertSame(1, $usages['d7.example']->mailboxes);
        $this->assertSame(1, $usages['d7.example']->aliases);
    }
}
