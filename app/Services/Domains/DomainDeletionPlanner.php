<?php

namespace App\Services\Domains;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\DomainAdmins\OrphanedDomainAdminRemover;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The deletion plan of a domain (research D17 step 3), used by the deletion and its preview
 * (SC-011): blocking mailboxes and resources, alias domains, the aliases of the domain and its alias
 * domains, the footer, the domain administrators that would be left without a domain, whether a
 * DKIM key exists, and the domain's version tag.
 */
class DomainDeletionPlanner
{
    private const SAMPLE = 100;

    public function __construct(
        private readonly OrphanedDomainAdminRemover $orphans,
        private readonly DomainVersion $version,
        private readonly SharedStore $store,
    ) {}

    public function plan(string $domain): ?DomainDeletionPlan
    {
        $row = DB::table('domain')->where('domain', $domain)->first();

        if ($row === null) {
            return null;
        }

        $tags = DB::table('tags_domain')->where('domain', $domain)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();
        $aliasDomains = $this->aliasDomains($domain);

        return new DomainDeletionPlan(
            domain: $domain,
            mailboxes: $this->sample(DB::table('mailbox')->where('domain', $domain)->where('kind', ''), 'username'),
            resources: $this->sample(DB::table('mailbox')->where('domain', $domain)->where('kind', '<>', ''), 'username'),
            aliasDomains: $aliasDomains,
            aliases: $this->sample(DB::table('alias')->whereIn('domain', [$domain, ...$aliasDomains]), 'address'),
            footer: DB::table('domain_wide_footer')->where('domain', $domain)->exists(),
            domainAdminsToDelete: $this->orphans->candidatesWithout($domain),
            dkimKeyKept: $this->hasDkimKey($domain),
            etag: $this->version->forDomain($row, $tags),
        );
    }

    /**
     * @return list<string>
     */
    public function aliasDomains(string $domain): array
    {
        return DB::table('alias_domain')->where('target_domain', $domain)->orderBy('alias_domain')->pluck('alias_domain')
            ->map(fn (mixed $a): string => (string) $a)->all();
    }

    public function hasDkimKey(string $domain): bool
    {
        try {
            return $this->store->hasField('DKIM_SELECTORS', $domain) || $this->store->hasField('DKIM_PUB_KEYS', $domain);
        } catch (SharedStoreUnavailable) {
            return false;
        }
    }

    /**
     * @return array{count: int, addresses: list<string>}
     */
    private function sample(Builder $query, string $column): array
    {
        return [
            'count' => (clone $query)->count(),
            'addresses' => (clone $query)->orderBy($column)->limit(self::SAMPLE)->pluck($column)->map(fn (mixed $a): string => (string) $a)->all(),
        ];
    }
}
