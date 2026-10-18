<?php

namespace App\Services\Aliases;

use App\Exceptions\ProblemException;
use App\Services\Domains\DomainUsageCalculator;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;

/**
 * The alias limit of a primary domain (feature 004 FR-022, FR-030, FR-037, FR-044, research R6):
 * the aliases in use, counted by 002's DomainUsageCalculator (the domain and its alias domains,
 * mailboxes' own rows not counted), plus those a request adds must not exceed `domain.aliases`.
 *
 * Callers lock the primary domain row inside their transaction before counting, so that two
 * requests for the last free alias of a domain serialise and exactly one succeeds (002's locking
 * convention). A request that adds nothing is never refused.
 */
class AliasLimit
{
    public function __construct(private readonly DomainUsageCalculator $usage) {}

    /** Locks the primary domain's row (`SELECT … FOR UPDATE`); null when it does not exist. */
    public function lockPrimaryDomain(string $primary): ?object
    {
        return DB::table('domain')->where('domain', $primary)->lockForUpdate()->first(['domain', 'aliases']);
    }

    /**
     * @throws ProblemException `409 domain-alias-limit-reached` with `domain`, `count`, `limit` and `requested`
     */
    public function assertRoom(string $primary, int $adding): void
    {
        if ($adding < 1) {
            return;
        }

        $limit = DB::table('domain')->where('domain', $primary)->value('aliases');

        if ($limit === null) {
            return;
        }

        $limit = (int) $limit;
        $count = $this->usage->forDomain($primary)->aliases ?? 0;

        if ($count + $adding > $limit) {
            throw new ProblemException(
                ProblemType::DomainAliasLimitReached,
                "The domain {$primary} has {$count} of {$limit} aliases in use.",
                ['domain' => $primary, 'count' => $count, 'limit' => $limit, 'requested' => $adding],
            );
        }
    }

    /** The primary domain of an alias's domain: the target of an alias domain, otherwise the domain itself. */
    public static function primaryOf(string $domain): string
    {
        return (string) (DB::table('alias_domain')->where('alias_domain', $domain)->value('target_domain') ?? $domain);
    }
}
