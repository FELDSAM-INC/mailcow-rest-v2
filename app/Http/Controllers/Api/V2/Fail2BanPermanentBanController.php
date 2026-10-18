<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListFail2BanBansRequest;
use App\Services\Fail2Ban\Fail2BanBanService;
use Illuminate\Http\JsonResponse;

/**
 * listFail2BanPermanentBans (feature 011 FR-052, FR-054): lifted by removing the denylist entry.
 */
class Fail2BanPermanentBanController extends Controller
{
    public function index(ListFail2BanBansRequest $request, Fail2BanBanService $bans): JsonResponse
    {
        return response()->json($bans->permanent($request->listQuery(true), $request->has('order')), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
