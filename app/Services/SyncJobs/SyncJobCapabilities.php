<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Support\Http\ListQuery;

/**
 * The sync-job capabilities of a caller (feature 008 FR-030, research R17): whether custom imapsync
 * parameters are allowed for them and the allow-list in effect (never `showpasswords`), the bulk
 * limit, the page sizes of every list, the field limits and the creation defaults. Computed from the
 * server's current configuration on every request; it contains no secret.
 */
class SyncJobCapabilities
{
    public function __construct(private readonly CustomParametersValidator $customParameters) {}

    public static function bulkLimit(): int
    {
        return max(1, min(100, (int) config('restv2.sync_jobs.bulk_limit', 100)));
    }

    /**
     * @return array<string, mixed>
     */
    public function for(Principal $principal): array
    {
        $limits = [];

        foreach (SyncJobLimits::RANGES as $member => [$minimum, $maximum]) {
            $limits[$member] = ['minimum' => $minimum, 'maximum' => $maximum];
        }

        foreach (SyncJobLimits::LENGTHS as $member => $length) {
            $limits[$member.'MaxLength'] = $length;
        }

        return [
            'customParameters' => [
                'allowed' => $this->customParameters->allowedFor($principal),
                'restrictedToAdministrators' => $this->customParameters->restrictedToAdministrators(),
                'allowedOptions' => $this->customParameters->allowedOptions(),
            ],
            'bulkLimit' => self::bulkLimit(),
            'pageSize' => ['default' => ListQuery::DEFAULT_LIMIT, 'maximum' => ListQuery::MAX_LIMIT],
            'limits' => $limits,
            'encryptions' => SyncJobEncryption::apiValues(),
            'defaults' => SyncJobDefaults::VALUES,
        ];
    }
}
