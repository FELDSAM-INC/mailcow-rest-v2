<?php

namespace App\Services\Routing;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;

/**
 * The `409` problems of feature 009 with their extension members (FR-087, docs/problems.md).
 */
final class RoutingConflict
{
    /** `routing-entry-exists`: the request member that collides and the existing entry's path ID. */
    public static function entryExists(string $field, int|string $conflictingId, string $detail): ProblemException
    {
        return new ProblemException(ProblemType::RoutingEntryExists, $detail, ['field' => $field, 'conflictingId' => (string) $conflictingId]);
    }

    /**
     * `next-hop-credentials-differ`: the next hop and its transports.
     *
     * @param  list<int>  $transportIds
     */
    public static function credentialsDiffer(string $nextHop, array $transportIds): ProblemException
    {
        return new ProblemException(
            ProblemType::NextHopCredentialsDiffer,
            "Transports with the next hop {$nextHop} use a different username. Send no credentials to share theirs.",
            ['nextHop' => $nextHop, 'transportIds' => $transportIds],
        );
    }

    /**
     * `next-hop-interferes`: the interfering stored next hop and its transports.
     *
     * @param  list<int>  $transportIds
     */
    public static function interferes(string $nextHop, string $requested, array $transportIds): ProblemException
    {
        return new ProblemException(
            ProblemType::NextHopInterferes,
            "{$nextHop} interferes with the next hop {$requested}.",
            ['nextHop' => $nextHop, 'transportIds' => $transportIds],
        );
    }
}
