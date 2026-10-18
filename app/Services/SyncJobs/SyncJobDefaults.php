<?php

namespace App\Services\SyncJobs;

/**
 * The values a new sync job gets for omitted members (clarification 8, FR-017): what the legacy
 * "Add sync job" form pre-fills. Used by creation and by the capabilities read (`SyncJobDefaults`).
 */
final class SyncJobDefaults
{
    /** @var array<string, string|int|bool> contract member => default */
    public const VALUES = [
        'encryption' => 'ssl',
        'exclude' => '(?i)spam|(?i)junk',
        'destinationSubfolder' => '',
        'intervalMinutes' => 20,
        'maxAgeDays' => 0,
        'maxBytesPerSecond' => 0,
        'remoteTimeoutSeconds' => 600,
        'localTimeoutSeconds' => 600,
        'deleteFromSource' => false,
        'deleteFromDestination' => false,
        'deleteDuplicatesOnDestination' => true,
        'automap' => true,
        'skipCrossDuplicates' => false,
        'subscribeAll' => true,
        'dryRun' => false,
        'customParameters' => '',
        'active' => true,
    ];
}
