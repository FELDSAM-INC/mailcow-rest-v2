<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\SyncJobLogResource;
use App\Policies\SyncJobPolicy;
use App\Services\SyncJobs\SyncJobQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getSyncJobLog (feature 008 FR-009): the run log of a job the caller may read. Not audited.
 */
class SyncJobLogController extends Controller
{
    public function show(Request $request, string $syncJobId, SyncJobPolicy $policy, SyncJobQuery $query): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal);

        $job = $query->findWithLog((int) $syncJobId, $principal) ?? throw new NotFoundHttpException('The sync job does not exist.');

        return SyncJobLogResource::response($job);
    }
}
