<?php

namespace App\Services\SyncJobs;

/**
 * The ranges of the whole-number members and the maximum lengths of the text members of a sync
 * job (feature 008 research R6, FR-017): legacy's form `min`/`max` attributes, checks and column
 * sizes. Used by validation and by the capabilities read.
 */
final class SyncJobLimits
{
    /** @var array<string, array{int, int}> contract member => [minimum, maximum] */
    public const RANGES = [
        'remotePort' => [1, 65535],
        'intervalMinutes' => [1, 43800],
        'maxAgeDays' => [0, 32000],
        'maxBytesPerSecond' => [0, 125000000],
        'remoteTimeoutSeconds' => [1, 32000],
        'localTimeoutSeconds' => [1, 32000],
    ];

    /** @var array<string, int> contract member => maximum length */
    public const LENGTHS = [
        'remoteHost' => 255,
        'remoteUser' => 255,
        'remotePassword' => 255,
        'destinationSubfolder' => 255,
        'exclude' => 500,
        'customParameters' => 512,
    ];

    /** The boolean members, in contract order. */
    public const FLAGS = [
        'deleteFromSource', 'deleteFromDestination', 'deleteDuplicatesOnDestination', 'automap',
        'skipCrossDuplicates', 'subscribeAll', 'dryRun', 'active',
    ];
}
