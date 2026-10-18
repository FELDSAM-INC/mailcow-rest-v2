<?php

namespace App\Http\Resources\Api\V2;

use App\Models\Mailcow\SyncJob;
use App\Services\SyncJobs\SyncJobLastResult;
use App\Services\SyncJobs\SyncJobVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The contract `SyncJob` (feature 008 research R3, FR-007, FR-008, FR-010) and its responses:
 * single resources with their `ETag`, lists with `{data, meta}`. The remote password is reported
 * only as `remotePasswordSet`, and the log only as `runStatus.logAvailable`. `exitStatus` is empty
 * while the last result is unknown, as legacy's table shows it.
 */
final class SyncJobResource
{
    /**
     * @return array<string, mixed>
     */
    public static function present(SyncJob $job, bool $logAvailable): array
    {
        return [
            'id' => (int) $job->id,
            'mailbox' => (string) $job->user2,
            'remoteHost' => (string) $job->host1,
            'remotePort' => (int) $job->port1,
            'encryption' => $job->enc1?->toApi() ?? 'starttls',
            'authMechanism' => 'plain',
            'remoteUser' => (string) $job->user1,
            'remotePasswordSet' => (string) $job->getAttribute('password1') !== '',
            'exclude' => (string) $job->exclude,
            'destinationSubfolder' => (string) $job->subfolder2,
            'intervalMinutes' => (int) $job->mins_interval,
            'maxAgeDays' => (int) $job->maxage,
            'maxBytesPerSecond' => (int) $job->maxbytespersecond,
            'remoteTimeoutSeconds' => (int) $job->timeout1,
            'localTimeoutSeconds' => (int) $job->timeout2,
            'deleteFromSource' => (bool) $job->delete1,
            'deleteFromDestination' => (bool) $job->delete2,
            'deleteDuplicatesOnDestination' => (bool) $job->delete2duplicates,
            'automap' => (bool) $job->automap,
            'skipCrossDuplicates' => (bool) $job->skipcrossduplicates,
            'subscribeAll' => (bool) $job->subscribeall,
            'dryRun' => (bool) $job->dry,
            'customParameters' => (string) $job->custom_params,
            'active' => (bool) $job->active,
            'runStatus' => self::runStatus($job, $logAvailable),
            'createdAt' => self::time($job->created),
            'modifiedAt' => self::time($job->modified),
            'etag' => (new SyncJobVersion)->of($job),
        ];
    }

    /**
     * The contract `SyncJobRunStatus`.
     *
     * @return array{lastRunAt: string|null, lastResult: string, exitStatus: string|null, running: bool, logAvailable: bool}
     */
    public static function runStatus(SyncJob $job, bool $logAvailable): array
    {
        $result = SyncJobLastResult::fromStored($job->getAttributes()['success'] ?? null);
        $exitStatus = $job->exit_status;

        return [
            'lastRunAt' => self::time($job->last_run),
            'lastResult' => $result->value,
            'exitStatus' => $result === SyncJobLastResult::Unknown || $exitStatus === null || $exitStatus === '' ? null : (string) $exitStatus,
            'running' => (bool) $job->is_running,
            'logAvailable' => $logAvailable,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array{total: int, limit: int, offset: int}  $meta
     */
    public static function list(array $data, array $meta): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function time(?CarbonImmutable $time): ?string
    {
        return $time?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
