<?php

namespace App\Services\Domains;

use Illuminate\Support\Facades\DB;

/**
 * Usage figures exactly as legacy mailbox('get', 'domain_details') computes them (FR-008, research
 * D5), for a page of domains at once with four grouped queries instead of six per domain:
 *
 *  - mailboxes (`kind = ''`), resources (`kind <> ''`), quota allocated and the largest mailbox
 *    quota, from `mailbox`;
 *  - storage and messages from `quota2`, over every mailbox of the domain, resources included;
 *  - aliases in the domain or its alias domains whose address is not itself a mailbox;
 *  - alias domains pointing to the domain.
 *
 * Unlike legacy, the quotas available to a new mailbox never fall below zero. Reused by features
 * 003 (mailbox limits and quota) and 004 (alias limit).
 */
class DomainUsageCalculator
{
    private const MIB = 1048576;

    /**
     * @param  list<string>  $domains  ASCII names of existing domains
     * @return array<string, DomainUsage> name => figures
     */
    public function forDomains(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        $limits = DB::table('domain')->whereIn('domain', $domains)
            ->get(['domain', 'mailboxes', 'aliases', 'maxquota', 'quota'])
            ->keyBy('domain');

        $mailboxes = DB::table('mailbox')
            ->whereIn('domain', $domains)
            ->groupBy('domain')
            ->get([
                'domain',
                DB::raw("SUM(`kind` = '') AS `mailboxes`"),
                DB::raw("SUM(`kind` <> '') AS `resources`"),
                DB::raw("COALESCE(SUM(CASE WHEN `kind` = '' THEN `quota` END), 0) AS `allocated`"),
                DB::raw("COALESCE(MAX(CASE WHEN `kind` = '' THEN `quota` END), 0) AS `largest`"),
            ])
            ->keyBy('domain');

        $storage = DB::table('quota2')
            ->join('mailbox', 'mailbox.username', '=', 'quota2.username')
            ->whereIn('mailbox.domain', $domains)
            ->groupBy('mailbox.domain')
            ->get(['mailbox.domain', DB::raw('COALESCE(SUM(`quota2`.`bytes`), 0) AS `bytes`'), DB::raw('COALESCE(SUM(`quota2`.`messages`), 0) AS `messages`')])
            ->keyBy('domain');

        $placeholders = implode(',', array_fill(0, count($domains), '?'));
        $aliases = collect(DB::select(
            "SELECT COALESCE(`ad`.`target_domain`, `a`.`domain`) AS `d`, COUNT(*) AS `count`
               FROM `alias` `a`
               LEFT JOIN `alias_domain` `ad` ON `ad`.`alias_domain` = `a`.`domain`
              WHERE (`a`.`domain` IN ({$placeholders}) OR `ad`.`target_domain` IN ({$placeholders}))
                AND `a`.`address` NOT IN (SELECT `username` FROM `mailbox`)
              GROUP BY `d`",
            [...$domains, ...$domains],
        ))->keyBy('d');

        $aliasDomains = DB::table('alias_domain')
            ->whereIn('target_domain', $domains)
            ->groupBy('target_domain')
            ->get(['target_domain', DB::raw('COUNT(*) AS `count`')])
            ->keyBy('target_domain');

        $result = [];

        foreach ($domains as $domain) {
            $row = $limits->get($domain);

            if ($row === null) {
                continue;
            }

            $m = $mailboxes->get($domain);
            $mailboxCount = (int) ($m->mailboxes ?? 0);
            $allocated = (int) ($m->allocated ?? 0);
            $aliasCount = (int) ($aliases->get($domain)->count ?? 0);
            $domainQuota = (int) $row->quota * self::MIB;
            $maxNew = max(0, min($domainQuota - $allocated, (int) $row->maxquota * self::MIB));

            $result[$domain] = new DomainUsage(
                mailboxes: $mailboxCount,
                resources: (int) ($m->resources ?? 0),
                aliases: $aliasCount,
                aliasDomains: (int) ($aliasDomains->get($domain)->count ?? 0),
                mailboxesLeft: (int) $row->mailboxes - $mailboxCount,
                aliasesLeft: (int) $row->aliases - $aliasCount,
                largestMailboxQuota: (int) ($m->largest ?? 0),
                quotaAllocated: $allocated,
                storageUsed: (int) ($storage->get($domain)->bytes ?? 0),
                messages: (int) ($storage->get($domain)->messages ?? 0),
                maxNewMailboxQuota: $maxNew,
            );
        }

        return $result;
    }

    public function forDomain(string $domain): ?DomainUsage
    {
        return $this->forDomains([$domain])[$domain] ?? null;
    }
}
