<?php

namespace App\Services\SyncJobs;

use App\Http\Resources\Api\V2\SyncJobResource;
use App\Models\Mailcow\SyncJob;
use stdClass;

/**
 * The `details` of sync-job audit records (feature 008 research R16, FR-025), in contract member
 * names and never with the remote password: a creation records the stored configuration with
 * `remotePasswordSet`, a change each changed member as `{old, new}` and a replaced password only as
 * `remotePassword: "changed"`. Refusals are recorded by the shared RefusalAuditor, which keeps rule
 * codes and never values.
 */
final class SyncJobAuditDetails
{
    /** The configuration members of a job, as audited (the password is never one of them). */
    private const MEMBERS = [
        'mailbox', 'remoteHost', 'remotePort', 'encryption', 'remoteUser', 'exclude', 'destinationSubfolder',
        'intervalMinutes', 'maxAgeDays', 'maxBytesPerSecond', 'remoteTimeoutSeconds', 'localTimeoutSeconds',
        'deleteFromSource', 'deleteFromDestination', 'deleteDuplicatesOnDestination', 'automap',
        'skipCrossDuplicates', 'subscribeAll', 'dryRun', 'customParameters', 'active',
    ];

    /**
     * The stored configuration of a job in contract form, without the password.
     *
     * @return array<string, mixed>
     */
    public static function configuration(SyncJob $job): array
    {
        $representation = SyncJobResource::present($job, false);

        return array_intersect_key($representation, array_flip(self::MEMBERS));
    }

    /**
     * The `values` of a creation record: the stored configuration and whether a password is set.
     *
     * @return array<string, mixed>
     */
    public static function values(SyncJob $job): array
    {
        return self::configuration($job) + ['remotePasswordSet' => (string) $job->getAttribute('password1') !== ''];
    }

    /**
     * The `changes` of a change record: every member whose value differs, and the password only as
     * changed. An object (not a list) also when nothing changed.
     *
     * @param  array<string, mixed>  $before  configuration() before the change
     * @param  array<string, mixed>  $after  configuration() after the change
     * @return array<string, mixed>|stdClass
     */
    public static function changes(array $before, array $after, bool $passwordReplaced): array|stdClass
    {
        $changes = [];

        foreach (self::MEMBERS as $member) {
            if (array_key_exists($member, $before) && ($after[$member] ?? null) !== $before[$member]) {
                $changes[$member] = ['old' => $before[$member], 'new' => $after[$member] ?? null];
            }
        }

        if ($passwordReplaced) {
            $changes['remotePassword'] = 'changed';
        }

        return $changes === [] ? new stdClass : $changes;
    }
}
