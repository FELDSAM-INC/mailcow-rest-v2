<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\AccountBulkActionRequest;
use App\Services\Accounts\AccountBulkActionRunner;
use Illuminate\Http\JsonResponse;

/**
 * applyAdminBulkAction and applyDomainAdminBulkAction (feature 007 FR-075, FR-076): the role is the
 * route's collection. Answers the shared `BulkResultResponse`.
 */
class AccountBulkActionController extends Controller
{
    public function store(AccountBulkActionRequest $request, AccountBulkActionRunner $runner): JsonResponse
    {
        $result = $runner->run($request, RequestContext::requirePrincipal($request), $request->role(), $request->action(), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
