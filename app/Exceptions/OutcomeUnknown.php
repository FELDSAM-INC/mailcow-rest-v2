<?php

namespace App\Exceptions;

use App\Support\Http\ProblemType;

/**
 * `504 outcome-unknown` (feature 003 FR-040, FR-048; shared with features 011 and 013): mailcow's
 * container service did not answer within DOCKER_TIMEOUT, so the outcome of one step is unknown.
 * `step` names it; a rename adds the outcome of every step.
 */
class OutcomeUnknown extends ProblemException
{
    /**
     * @param  list<array{step: string, outcome: string}>|null  $steps
     * @param  array<string, mixed>  $auditDetails
     */
    public function __construct(string $step, string $detail, ?array $steps = null, array $auditDetails = [])
    {
        parent::__construct(
            ProblemType::OutcomeUnknown,
            $detail,
            ['step' => $step] + ($steps === null ? [] : ['steps' => $steps]),
            [],
            $auditDetails,
        );
    }
}
