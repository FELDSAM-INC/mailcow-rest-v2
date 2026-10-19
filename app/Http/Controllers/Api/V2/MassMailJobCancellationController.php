<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\MassMail\MassMailJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * cancelMassMailJob (feature 013 FR-078): a signed-in administrator cancels a running job.
 */
class MassMailJobCancellationController extends Controller
{
    public function store(Request $request, string $jobId, SettingsPolicy $policy, MassMailJobService $jobs): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->requireAdmin($principal);

        return SettingsResource::response($jobs->cancel($jobId, $principal));
    }
}
