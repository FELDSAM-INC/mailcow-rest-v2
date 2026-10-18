<?php

namespace App\Exceptions;

use App\Support\Http\ProblemType;

/**
 * `503 temporary-failure` of feature 003 (FR-040, FR-048, contract `MailboxTemporaryFailureProblem`):
 * a mailcow service the operation needs is unavailable or reported a failure, and nothing was
 * changed (for a rename: every step already done was undone). `dependency` names the service
 * (`mailStore`, `webmail`, `sharedStore`, or `database` for a rename's database step); a rename adds
 * the outcome of every step. A retry makes sense after `Retry-After` seconds.
 */
class MailboxDependencyUnavailable extends ProblemException
{
    public const RETRY_AFTER = 30;

    /**
     * @param  list<array{step: string, outcome: string}>|null  $steps
     * @param  array<string, mixed>  $auditDetails
     */
    public function __construct(string $dependency, string $detail, ?array $steps = null, array $auditDetails = [])
    {
        parent::__construct(
            ProblemType::TemporaryFailure,
            $detail,
            ['dependency' => $dependency] + ($steps === null ? [] : ['steps' => $steps]),
            ['Retry-After' => (string) self::RETRY_AFTER],
            $auditDetails,
        );
    }
}
