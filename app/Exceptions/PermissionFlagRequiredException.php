<?php

namespace App\Exceptions;

use App\Auth\PermissionFlag;
use App\Support\Http\ProblemType;

/**
 * `403 permission-flag-required` with the shared extension members of
 * `PermissionFlagRequiredProblem` (G34, docs/conventions.md "Permission refusals"): the flags as
 * the property names of `PermissionFlags`, and how they combine (`all`: every listed flag is
 * needed and only the missing ones are listed; `any`: one of them suffices).
 */
class PermissionFlagRequiredException extends ProblemException
{
    /**
     * @param  list<PermissionFlag|string>  $flags
     */
    public function __construct(array $flags, string $match = 'all', ?string $detail = null)
    {
        $names = array_map(fn (PermissionFlag|string $flag): string => $flag instanceof PermissionFlag ? $flag->value : $flag, $flags);

        parent::__construct(
            ProblemType::PermissionFlagRequired,
            $detail ?? ($match === 'any'
                ? 'This operation needs one of these permission flags: '.implode(', ', $names).'.'
                : 'This operation needs these permission flags: '.implode(', ', $names).'.'),
            ['requiredFlags' => $names, 'requiredFlagsMatch' => $match === 'any' ? 'any' : 'all'],
        );
    }
}
