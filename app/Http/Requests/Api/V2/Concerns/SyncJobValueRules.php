<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use App\Rules\SyncJobExcludePattern;
use App\Rules\SyncJobHostName;
use App\Services\SyncJobs\SyncJobLimits;

/**
 * The rules of the members sync-job creations and changes share (feature 008 FR-017, research R6):
 * strict JSON types (a numeric string is not an integer, `0` and `1` are not booleans, `null` is no
 * value), the ranges and lengths of SyncJobLimits, legacy's host rule and the PCRE check of the
 * exclusion pattern. Values are refused, never clamped.
 */
trait SyncJobValueRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function valueRules(): array
    {
        $rules = [
            'remoteHost' => ['bail', 'sometimes', 'string', 'filled', 'max:'.SyncJobLimits::LENGTHS['remoteHost'], new SyncJobHostName],
            'remoteUser' => ['bail', 'sometimes', 'string', 'filled', 'max:'.SyncJobLimits::LENGTHS['remoteUser']],
            'remotePassword' => ['bail', 'sometimes', 'string', 'max:'.SyncJobLimits::LENGTHS['remotePassword']],
            'encryption' => ['bail', 'sometimes', 'string', 'in:ssl,starttls,plain'],
            'exclude' => ['bail', 'sometimes', 'string', 'max:'.SyncJobLimits::LENGTHS['exclude'], new SyncJobExcludePattern],
            'destinationSubfolder' => ['bail', 'sometimes', 'string', 'max:'.SyncJobLimits::LENGTHS['destinationSubfolder']],
            'customParameters' => ['bail', 'sometimes', 'string', 'max:'.SyncJobLimits::LENGTHS['customParameters']],
        ];

        foreach (SyncJobLimits::RANGES as $member => [$minimum, $maximum]) {
            $rules[$member] = ['bail', 'sometimes', new StrictInteger, 'integer', "between:{$minimum},{$maximum}"];
        }

        foreach (SyncJobLimits::FLAGS as $member) {
            $rules[$member] = ['bail', 'sometimes', new StrictBoolean];
        }

        return $rules;
    }
}
