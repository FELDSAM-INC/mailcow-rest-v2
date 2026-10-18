<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\SyncJobResource;
use App\Policies\SyncJobPolicy;
use App\Services\SyncJobs\SyncJobQuery;
use App\Services\SyncJobs\SyncJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * rescheduleSyncJob (feature 008 FR-016, FR-021): clears the job's last run time and last result
 * and answers `200` with the job; `If-Match` is neither required nor checked.
 */
class SyncJobScheduleResetController extends Controller
{
    public function store(Request $request, string $syncJobId, SyncJobPolicy $policy, SyncJobService $service, SyncJobQuery $query): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal);

        $job = $service->reschedule($principal, (int) $syncJobId);

        return SyncJobResource::response(SyncJobResource::present($job, $query->logAvailability([(int) $job->id])[(int) $job->id] ?? false));
    }
}
