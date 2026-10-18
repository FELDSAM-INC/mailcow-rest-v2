<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Rules\MtaStsMxHost;
use App\Rules\OneOf;
use App\Rules\StrictBoolean;
use App\Rules\StrictInteger;
use Illuminate\Contracts\Validation\Validator;

/**
 * The member rules of MTA-STS policies (feature 010 FR-033, research R15, data-model), shared by
 * creation and change:
 *
 *  - `version`: string, max 16, equal to `stsv1` case-insensitively (`notInList`, `allowed: [STSv1]`);
 *  - `mode`: string, max 16, one of `enforce`, `testing`, `none` case-insensitively (`notInList`);
 *  - `maxAge`: integer from 1 to 31,557,600 (`invalidType`, `outOfRange`);
 *  - `mxHosts`: 1 to 128 strings of at most 255 characters, each an MX host (`invalidFormat`,
 *    `format: mxHost`, on `mxHosts.<index>`); normalised and deduplicated (first occurrence kept),
 *    joined by `,` at most 255 characters (`tooLong`, `max: 255`, on `mxHosts`);
 *  - `active`: boolean (`invalidType`).
 *
 * At creation `mode`, `maxAge` and `mxHosts` are required; in a patch no member may be `null`.
 */
trait MtaStsPolicyRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function policyRules(bool $creation): array
    {
        $presence = $creation ? 'required' : 'sometimes';

        return [
            'version' => ['bail', 'sometimes', 'string', 'max:16', new OneOf(['STSv1'], true)],
            'mode' => ['bail', $presence, 'string', 'max:16', new OneOf(['enforce', 'testing', 'none'], true)],
            'maxAge' => ['bail', $presence, new StrictInteger, 'integer', 'between:1,'.(int) config('restv2.mta_sts.max_age_max')],
            'mxHosts' => ['bail', $presence, 'array', 'list', 'filled', 'max:128'],
            'mxHosts.*' => ['bail', 'string', 'max:255', new MtaStsMxHost],
            'active' => ['bail', 'sometimes', new StrictBoolean],
        ];
    }

    /**
     * The 255-character limit of mailcow's `mta_sts.mx` column, checked on the normalised hosts.
     *
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    protected function additionalViolations(Validator $validator): array
    {
        foreach ($validator->errors()->keys() as $key) {
            if ($key === 'mxHosts' || str_starts_with($key, 'mxHosts.')) {
                return [];
            }
        }

        $hosts = $this->normalisedMxHosts();
        $max = (int) config('restv2.mta_sts.mx_max_length');

        return $hosts !== null && strlen(implode(',', $hosts)) > $max
            ? ['mxHosts' => [self::make('tooLong', ['max' => $max], "The MX hosts joined by commas must be at most {$max} characters long.")]]
            : [];
    }

    /**
     * The sent MX hosts, normalised, without duplicates, in their order; null when not sent.
     *
     * @return list<string>|null
     */
    protected function normalisedMxHosts(): ?array
    {
        $hosts = $this->input('mxHosts');

        if (! is_array($hosts)) {
            return null;
        }

        $normalised = [];

        foreach ($hosts as $host) {
            $name = is_string($host) ? MtaStsMxHost::normalize($host) : null;

            if ($name !== null && ! in_array($name, $normalised, true)) {
                $normalised[] = $name;
            }
        }

        return $normalised;
    }
}
