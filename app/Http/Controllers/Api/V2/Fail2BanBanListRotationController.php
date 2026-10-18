<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Fail2Ban\Fail2BanSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * rotateFail2BanBanListId (feature 011 FR-055): a new ban list ID; the old address stops working.
 */
class Fail2BanBanListRotationController extends Controller
{
    public function store(Request $request, Fail2BanSettingsService $settings): JsonResponse
    {
        $result = $settings->rotate(RequestContext::requirePrincipal($request), $request->header('If-Match'));

        return response()->json(['data' => $result['data']], 200, ['ETag' => $result['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($result['auditRecorded']);
    }
}
