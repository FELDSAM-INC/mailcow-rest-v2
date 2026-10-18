<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Fail2Ban\Fail2BanBanService;
use App\Services\Monitoring\MonitoringAuditTargetResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * liftFail2BanBan (feature 011 FR-053): an action on an active ban, identified by its network.
 */
class Fail2BanBanLiftController extends Controller
{
    public function store(Request $request, Fail2BanBanService $bans): JsonResponse
    {
        $result = $bans->act(RequestContext::requirePrincipal($request), 'lift', (string) MonitoringAuditTargetResolver::network($request));

        return response()->json(['data' => $result['data']], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($result['auditRecorded']);
    }
}
