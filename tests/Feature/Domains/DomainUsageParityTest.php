<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T039 (SC-001): every usage figure of getDomain equals what legacy
 * mailbox('get', 'domain_details') computes for the same data, running legacy's own statements
 * (tests/fixtures/legacy/domain_details_queries.php).
 */
class DomainUsageParityTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * Legacy's figures of one domain, computed as mailbox('get', 'domain_details') does.
     *
     * @return array<string, int>
     */
    private function legacy(string $domain): array
    {
        $queries = require __DIR__.'/../../fixtures/legacy/domain_details_queries.php';
        $pdo = DB::connection()->getPdo();
        $run = function (string $sql, array $params) use ($pdo): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
        };

        $row = $run($queries['domain'], [':domain' => $domain]);
        $mailboxes = $run($queries['mailboxes'], [':domain' => $domain]);
        $quota = $run($queries['quota'], [':domain' => $domain]);
        $aliases = $run($queries['aliases'], [':domain' => $domain, ':domain2' => $domain]);

        // The arithmetic of domain_details, verbatim in meaning.
        $maxNew = ($row['quota'] * 1048576) - $mailboxes['in_use'];
        if ($maxNew > ($row['maxquota'] * 1048576)) {
            $maxNew = ($row['maxquota'] * 1048576);
        }
        $defNew = $maxNew;
        if ($defNew > ($row['defquota'] * 1048576)) {
            $defNew = ($row['defquota'] * 1048576);
        }

        return [
            'mboxes_in_domain' => (int) $mailboxes['count'],
            'mboxes_left' => (int) $row['mailboxes'] - (int) $mailboxes['count'],
            'aliases_in_domain' => (int) $aliases['alias_count'],
            'aliases_left' => (int) $row['aliases'] - (int) $aliases['alias_count'],
            'quota_used_in_domain' => (int) $mailboxes['in_use'],
            'bytes_total' => (int) ($quota['bytes_total'] ?? 0),
            'msgs_total' => (int) ($quota['msgs_total'] ?? 0),
            'max_new_mailbox_quota' => (int) $maxNew,
            'def_new_mailbox_quota' => (int) $defNew,
        ];
    }

    private function seedTwelveDomains(): void
    {
        $f = $this->mailcow();
        $relay = [
            [], ['backupmx' => 1], ['backupmx' => 1, 'relay_all_recipients' => 1],
            ['backupmx' => 1, 'relay_all_recipients' => 1, 'relay_unknown_only' => 1],
        ];

        for ($i = 0; $i < 12; $i++) {
            $d = "p{$i}.example";
            $f->domain($d, $i % 5 === 4 ? 0 : 1, $relay[$i % 4] + ['mailboxes' => 3 + $i, 'aliases' => $i, 'defquota' => 1024 * (1 + $i % 3), 'maxquota' => 4096, 'quota' => 2048 * (1 + $i % 4)]);

            for ($m = 0; $m < $i % 4; $m++) {
                $f->mailbox("m{$m}@{$d}", quota: (1 + $m) * 512 * self::MIB);
                $f->alias("m{$m}@{$d}", "m{$m}@{$d}");
                if ($m === 0) {
                    $f->quota2("m{$m}@{$d}", 1000 * ($i + 1), $i + 1);
                }
            }

            if ($i % 3 === 0) {
                $f->mailbox("room@{$d}", kind: 'location', quota: 99 * self::MIB);
                $f->quota2("room@{$d}", 77, 7);
            }

            for ($a = 0; $a < $i % 5; $a++) {
                $f->alias("alias{$a}@{$d}", "m0@{$d}");
            }

            if ($i % 2 === 1) {
                $f->aliasDomain("ad{$i}.example", $d);
                $f->alias("x@ad{$i}.example", "m0@{$d}");
                $f->alias("@ad{$i}.example", "m0@{$d}");
            }
        }
    }

    public function test_every_figure_equals_the_legacy_computation(): void
    {
        $this->seedTwelveDomains();
        $this->asAdmin();

        for ($i = 0; $i < 12; $i++) {
            $d = "p{$i}.example";
            $usage = $this->getJson('/api/v2/domains/'.$d)->assertOk()->json('data.usage');
            $legacy = $this->legacy($d);

            $this->assertSame($legacy['mboxes_in_domain'], $usage['mailboxes'], "{$d} mailboxes");
            $this->assertSame($legacy['mboxes_left'], $usage['mailboxesLeft'], "{$d} mailboxesLeft");
            $this->assertSame($legacy['aliases_in_domain'], $usage['aliases'], "{$d} aliases");
            $this->assertSame($legacy['aliases_left'], $usage['aliasesLeft'], "{$d} aliasesLeft");
            $this->assertSame($legacy['quota_used_in_domain'], $usage['quotaAllocated'], "{$d} quotaAllocated");
            $this->assertSame($legacy['bytes_total'], $usage['storageUsed'], "{$d} storageUsed");
            $this->assertSame($legacy['msgs_total'], $usage['messages'], "{$d} messages");
            // Legacy can go below zero; FR-008 floors at zero.
            $this->assertSame(max(0, $legacy['max_new_mailbox_quota']), $usage['maxNewMailboxQuota'], "{$d} maxNewMailboxQuota");
            $this->assertSame(max(0, $legacy['def_new_mailbox_quota']), $usage['defaultNewMailboxQuota'], "{$d} defaultNewMailboxQuota");
        }
    }
}
