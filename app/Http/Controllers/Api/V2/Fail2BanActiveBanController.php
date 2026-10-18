<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListFail2BanBansRequest;
use App\Services\Fail2Ban\Fail2BanBanService;
use Illuminate\Http\JsonResponse;

/**
 * listFail2BanActiveBans (feature 011 FR-052).
 */
class Fail2BanActiveBanController extends Controller
{
    public function index(ListFail2BanBansRequest $request, Fail2BanBanService $bans): JsonResponse
    {
        return response()->json($bans->active($request->listQuery(false), $request->queuedForLifting(), $request->has('order')), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
