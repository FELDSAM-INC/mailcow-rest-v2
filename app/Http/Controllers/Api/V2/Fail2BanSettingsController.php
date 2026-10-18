<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\UpdateFail2BanSettingsRequest;
use App\Services\Fail2Ban\Fail2BanSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * getFail2BanSettings and updateFail2BanSettings (feature 011 FR-044 to FR-048, FR-057, FR-061).
 */
class Fail2BanSettingsController extends Controller
{
    public function show(Fail2BanSettingsService $settings): JsonResponse
    {
        $result = $settings->read();

        return response()->json(['data' => $result['data']], 200, ['ETag' => $result['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function update(UpdateFail2BanSettingsRequest $request, Fail2BanSettingsService $settings): JsonResponse
    {
        $result = $settings->update(RequestContext::requirePrincipal($request), $request->patch(), $request->header('If-Match'));

        return response()->json(['data' => $result['data']], 200, ['ETag' => $result['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($result['auditRecorded']);
    }
}
