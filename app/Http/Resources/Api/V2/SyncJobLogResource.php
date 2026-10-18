<?php

namespace App\Http\Resources\Api\V2;

use App\Models\Mailcow\SyncJob;
use Illuminate\Http\JsonResponse;

/**
 * The contract `SyncJobLog` (feature 008 FR-009, research R14): the runner's stored output of the
 * job's last run, returned whole, with the run status. imapsync output may hold bytes of remote
 * folder names that are not UTF-8; they are replaced by U+FFFD, so that the response stays JSON.
 */
final class SyncJobLogResource
{
    public static function response(SyncJob $job): JsonResponse
    {
        $log = (string) ($job->getAttributes()['returned_text'] ?? '');

        return response()->json([
            'data' => [
                'syncJobId' => (int) $job->id,
                'runStatus' => SyncJobResource::runStatus($job, $log !== ''),
                'log' => $log,
            ],
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
