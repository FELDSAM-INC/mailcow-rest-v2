<?php

namespace App\Services\SyncJobs;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ValidationProblemException;
use App\Http\Resources\Api\V2\SyncJobResource;
use App\Models\Mailcow\SyncJob;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Every write of the `imapsync` table (feature 008, constitution IV): legacy's mailbox('add'|'edit'|
 * 'delete', 'syncjob') with the deviations the spec states. Each write and its audit record share one
 * transaction, so a change whose record cannot be written does not happen. Sync jobs have no other
 * side effect in legacy: no other table, no Redis key, no groupware refresh (legacy's closing
 * update_sogo_static_view() block skips the `syncjob` type).
 */
class SyncJobService
{
    /** Contract member => `imapsync` column of the changeable configuration (`mailbox` is fixed). */
    private const COLUMNS = [
        'remoteHost' => 'host1',
        'remotePort' => 'port1',
        'encryption' => 'enc1',
        'remoteUser' => 'user1',
        'remotePassword' => 'password1',
        'exclude' => 'exclude',
        'destinationSubfolder' => 'subfolder2',
        'intervalMinutes' => 'mins_interval',
        'maxAgeDays' => 'maxage',
        'maxBytesPerSecond' => 'maxbytespersecond',
        'remoteTimeoutSeconds' => 'timeout1',
        'localTimeoutSeconds' => 'timeout2',
        'deleteFromSource' => 'delete1',
        'deleteFromDestination' => 'delete2',
        'deleteDuplicatesOnDestination' => 'delete2duplicates',
        'automap' => 'automap',
        'skipCrossDuplicates' => 'skipcrossduplicates',
        'subscribeAll' => 'subscribeall',
        'dryRun' => 'dry',
        'customParameters' => 'custom_params',
        'active' => 'active',
    ];

    public function __construct(
        private readonly SyncJobDuplicateGuard $duplicates,
        private readonly AuditLogger $audit,
        private readonly SyncJobScope $scope,
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
        $this->assertSafeParameters($customParameters);
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
     * Changes a job (FR-016, FR-018, research R11), JSON Merge Patch semantics on validated
     * members: activation and deactivation are changes of `active`. On the locked row, the version
     * is checked (`412`), then only the configuration columns whose value differs are written, so a
     * status the runner wrote meanwhile is never overwritten (legacy rewrites the whole row). An
     * empty password keeps the stored one. A new remote user or host that duplicates another job of
     * the mailbox is a conflict. A change of nothing is audited with empty `changes`.
     *
     * @param  array<string, mixed>  $patch  validated members of `SyncJobPatch` (`mailbox` is ignored)
     *
     * @throws NotFoundHttpException for a job that does not exist or lies outside the caller's scope
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch = null, ?string $bulkRequestId = null): SyncJob
    {
        $current = $this->scope->findInScope($id, $principal) ?? throw self::notFound();
        $columns = self::changedColumns($current, $patch);

        $apply = function () use ($principal, $id, $patch, $ifMatch, $bulkRequestId): SyncJob {
            $job = $this->locked($principal, $id, $ifMatch);
            $before = SyncJobAuditDetails::configuration($job);
            $columns = self::changedColumns($job, $patch);

            if ((int) ($columns['active'] ?? $job->active) !== 0) {
                $this->assertSafeParameters((string) ($columns['custom_params'] ?? $job->custom_params));
            }

            if ($columns !== []) {
                DB::table('imapsync')->where('id', $id)->update($columns);
            }

            $after = self::load($id);
            $details = [
                'mailbox' => (string) $after->user2,
                'changes' => SyncJobAuditDetails::changes($before, SyncJobAuditDetails::configuration($after), array_key_exists('password1', $columns)),
            ];

            $this->audit->record(AuditEventType::SyncJobUpdate, true, $principal, self::target($after), null, null, self::withBulk($details, $bulkRequestId));

            return $after;
        };

        if (! array_key_exists('user1', $columns) && ! array_key_exists('host1', $columns)) {
            return DB::transaction($apply);
        }

        return $this->duplicates->run((string) $current->user2, (string) ($columns['user1'] ?? $current->user1), (string) ($columns['host1'] ?? $current->host1), $id, $apply);
    }

    /**
     * Clears the last run time and the last result (legacy "Schedule next", FR-016, research R12),
     * so the runner selects the job on its next cycle. Run status only: no version check, and the
     * version tag stays the same. A run in progress is not interrupted.
     */
    public function reschedule(Principal $principal, int $id, ?string $bulkRequestId = null): SyncJob
    {
        return DB::transaction(function () use ($principal, $id, $bulkRequestId): SyncJob {
            $job = $this->locked($principal, $id, null);
            $this->assertSafeParameters((string) $job->custom_params);
            $previous = SyncJobResource::runStatus($job, false);

            DB::table('imapsync')->where('id', $id)->update(['last_run' => null, 'success' => null]);
            $after = self::load($id);

            $this->audit->record(AuditEventType::SyncJobReschedule, true, $principal, self::target($after), null, null, self::withBulk([
                'mailbox' => (string) $after->user2,
                'cleared' => ['lastRunAt', 'lastResult'],
                'previous' => ['lastRunAt' => $previous['lastRunAt'], 'lastResult' => $previous['lastResult']],
            ], $bulkRequestId));

            return $after;
        });
    }

    /**
     * Deletes a job (FR-016, research R13) after the version check. A run in progress is not
     * interrupted; the runner's final status write then matches no row.
     */
    public function delete(Principal $principal, int $id, ?string $ifMatch = null, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $job = $this->locked($principal, $id, $ifMatch);

            DB::table('imapsync')->where('id', $id)->delete();

            $this->audit->record(AuditEventType::SyncJobDeletion, true, $principal, self::target($job), null, null, self::withBulk([
                'mailbox' => (string) $job->user2,
                'remoteHost' => (string) $job->host1,
                'remoteUser' => (string) $job->user1,
            ], $bulkRequestId));
        });
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

    private function assertSafeParameters(string $value): void
    {
        $violations = app(CustomParametersValidator::class)->safetyViolations($value);
        if ($violations !== []) {
            throw new ValidationProblemException(['customParameters' => $violations]);
        }
    }

    /**
     * The job's row locked for the change, in the caller's scope, with its version checked.
     */
    private function locked(Principal $principal, int $id, ?string $ifMatch): SyncJob
    {
        $job = $this->scope->findInScopeForUpdate($id, $principal) ?? throw self::notFound();

        if (ETag::preconditionFails($ifMatch, (new SyncJobVersion)->of($job))) {
            throw new PreconditionFailedHttpException('The sync job was changed since it was read.');
        }

        return $job;
    }

    /**
     * The configuration columns a patch changes, with legacy's stored formats: unchanged members
     * (compared as the representation shows them) and an empty password write nothing; custom
     * parameters are trimmed, and a subfolder `"0"` is empty as in legacy's edit.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, string|int|null>
     */
    private static function changedColumns(SyncJob $job, array $patch): array
    {
        $stored = $job->getAttributes();
        $columns = [];

        foreach (array_intersect_key($patch, self::COLUMNS) as $member => $value) {
            $column = self::COLUMNS[$member];
            $old = $stored[$column] ?? null;

            [$new, $unchanged] = match ($member) {
                'remotePassword' => [(string) $value, $value === '' || (string) $value === (string) $old],
                'encryption' => [$enc = SyncJobEncryption::fromApi((string) $value)->value, $enc === (string) $old],
                'customParameters' => [$params = trim((string) $value), $value === (string) $old || $params === (string) $old],
                'destinationSubfolder' => [$folder = ((string) $value === '0' ? '' : (string) $value), $folder === (string) $old],
                'remoteHost', 'remoteUser', 'exclude' => [(string) $value, (string) $value === (string) $old],
                'maxBytesPerSecond' => [(string) (int) $value, (int) $value === (int) $old],
                'deleteFromSource', 'deleteFromDestination', 'deleteDuplicatesOnDestination', 'automap',
                'skipCrossDuplicates', 'subscribeAll', 'dryRun', 'active' => [$value === true ? 1 : 0, $value === ((int) $old !== 0)],
                default => [(int) $value, (int) $value === (int) $old],
            };

            if (! $unchanged) {
                $columns[$column] = $new;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function withBulk(array $details, ?string $bulkRequestId): array
    {
        return $bulkRequestId === null ? $details : $details + ['bulkRequestId' => $bulkRequestId];
    }

    private static function notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('The sync job does not exist.');
    }
}
