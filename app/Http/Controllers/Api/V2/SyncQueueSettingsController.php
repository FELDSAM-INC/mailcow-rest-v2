<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\UpdateSyncQueueSettingsRequest;
use App\Services\SyncJobs\SyncQueueSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncQueueSettingsController extends Controller
{
    public function show(Request $request, SyncQueueSettings $settings): JsonResponse
    {
        $data = $settings->read(RequestContext::requirePrincipal($request));

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }

    public function update(UpdateSyncQueueSettingsRequest $request, SyncQueueSettings $settings): JsonResponse
    {
        $data = $settings->save(RequestContext::requirePrincipal($request), $request->values(), $request->header('If-Match'));

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }
}
