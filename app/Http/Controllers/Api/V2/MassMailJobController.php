<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\ListMassMailJobsRequest;
use App\Http\Requests\Api\V2\Settings\StoreMassMailJobRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\MassMail\MassMailJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listMassMailJobs, createMassMailJob and getMassMailJob (feature 013 FR-074 to FR-081). Creating
 * needs a signed-in administrator (the route refuses keys with `session-required`).
 */
class MassMailJobController extends Controller
{
    public function index(ListMassMailJobsRequest $request, MassMailJobService $jobs): JsonResponse
    {
        return response()->json($jobs->list($request->listQuery(), $request->status()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function store(StoreMassMailJobRequest $request, MassMailJobService $jobs): JsonResponse
    {
        $job = $jobs->create($request->values(), $request->selection(), RequestContext::requirePrincipal($request), $request);

        return SettingsResource::response($job, 201, null, ['Location' => '/api/v2/mass-mail-jobs/'.$job['id']]);
    }

    public function show(Request $request, string $jobId, SettingsPolicy $policy, MassMailJobService $jobs): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($jobs->find($jobId) ?? throw new NotFoundHttpException('The mass mail job does not exist.'));
    }
}
