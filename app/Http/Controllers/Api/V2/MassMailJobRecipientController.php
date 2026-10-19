<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Settings\ListMassMailJobRecipientsRequest;
use App\Services\MassMail\MassMailJobService;
use Illuminate\Http\JsonResponse;

/**
 * listMassMailJobRecipients (feature 013 FR-077): a job's recipients with their outcomes.
 */
class MassMailJobRecipientController extends Controller
{
    public function index(ListMassMailJobRecipientsRequest $request, string $jobId, MassMailJobService $jobs): JsonResponse
    {
        return response()->json($jobs->recipients($jobId, $request->listQuery(), $request->outcome()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
