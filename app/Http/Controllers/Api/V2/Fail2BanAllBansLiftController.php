<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Fail2Ban\Fail2BanBanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Fail2BanAllBansLiftController extends Controller
{
    public function store(Request $request, Fail2BanBanService $bans): JsonResponse
    {
        $result = $bans->liftAll(RequestContext::requirePrincipal($request));

        return response()->json(['data' => $result['data']])->withAuditRecorded($result['auditRecorded']);
    }
}
