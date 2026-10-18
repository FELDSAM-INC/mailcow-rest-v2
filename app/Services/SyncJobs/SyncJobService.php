<?php

namespace App\Services\SyncJobs;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\Mailcow\SyncJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Every write of the `imapsync` table (feature 008, constitution IV): legacy's mailbox('add',
 * 'syncjob') with the deviations the spec states. Each write and its audit record share one
 * transaction, so a change whose record cannot be written does not happen. Sync jobs have no other
 * side effect in legacy: no other table, no Redis key, no groupware refresh (legacy's closing
 * update_sogo_static_view() block skips the `syncjob` type).
 */
class SyncJobService
{
    public function __construct(
        private readonly SyncJobDuplicateGuard $duplicates,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Creates a job (FR-016, research R10): omitted members take the add form's defaults, and the
     * row gets exactly legacy's insert column list (the host lower-cased, the custom parameters
     * trimmed, `authmech1 = 'PLAIN'`, `maxbytespersecond` as a decimal string); every other column
     * keeps its database default. A job for the same mailbox, remote user and remote host is a
     * conflict.
     *
     * @param  array<string, mixed>  $members  the validated members of `SyncJobCreateRequest`
     * @param  string  $mailbox  the resolved target mailbox (its stored username)
     */
    public function create(Principal $principal, array $members, string $mailbox): SyncJob
    {
        $values = array_merge(SyncJobDefaults::VALUES, array_intersect_key($members, SyncJobDefaults::VALUES));
        $flag = fn (string $member): int => $values[$member] === true ? 1 : 0;
        $customParameters = trim((string) $values['customParameters']);
        $subfolder = (string) $values['destinationSubfolder'];

        // Legacy's INSERT column list and order (functions.mailbox.inc.php, mailbox('add', 'syncjob')).
        $row = [
            'user2' => $mailbox,
            'exclude' => (string) $values['exclude'],
            'delete1' => $flag('deleteFromSource'),
            'delete2' => $flag('deleteFromDestination'),
            'timeout1' => (int) $values['remoteTimeoutSeconds'],
            'timeout2' => (int) $values['localTimeoutSeconds'],
            'automap' => $flag('automap'),
            'skipcrossduplicates' => $flag('skipCrossDuplicates'),
            'maxbytespersecond' => (string) (int) $values['maxBytesPerSecond'],
            'subscribeall' => $flag('subscribeAll'),
            'dry' => $flag('dryRun'),
            'maxage' => (int) $values['maxAgeDays'],
            // Legacy: `if (empty($subfolder2)) $subfolder2 = "";`
            'subfolder2' => $subfolder === '0' ? '' : $subfolder,
            'host1' => strtolower((string) $members['remoteHost']),
            'authmech1' => 'PLAIN',
            'user1' => (string) $members['remoteUser'],
            'password1' => (string) $members['remotePassword'],
            'mins_interval' => (int) $values['intervalMinutes'],
            'port1' => (int) $members['remotePort'],
            'enc1' => SyncJobEncryption::fromApi((string) $values['encryption'])->value,
            'delete2duplicates' => $flag('deleteDuplicatesOnDestination'),
            // Legacy: `(empty(trim($custom_params))) ? '' : trim($custom_params)`
            'custom_params' => $customParameters === '0' ? '' : $customParameters,
            'active' => $flag('active'),
        ];

        $id = $this->duplicates->run($mailbox, $row['user1'], $row['host1'], null, function () use ($row, $principal, $mailbox): int {
            $id = (int) DB::table('imapsync')->insertGetId($row);
            $job = self::load($id);
            $this->audit->record(AuditEventType::SyncJobCreation, true, $principal, self::target($job), null, null, [
                'mailbox' => $mailbox,
                'values' => SyncJobAuditDetails::values($job),
            ]);

            return $id;
        });

        return self::load($id);
    }

    /**
     * The audit target of a job: its identifier and target mailbox.
     *
     * @return array{type: string, id: string, name: string}
     */
    public static function target(SyncJob $job): array
    {
        return ['type' => 'syncJob', 'id' => (string) $job->id, 'name' => (string) $job->user2];
    }

    private static function load(int $id): SyncJob
    {
        return SyncJob::query()->select(SyncJobQuery::columns())->whereKey($id)->firstOrFail();
    }
}
