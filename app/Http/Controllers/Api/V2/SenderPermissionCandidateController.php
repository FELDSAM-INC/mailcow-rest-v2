<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListSenderPermissionCandidatesRequest;
use App\Services\SenderSettings\PermissionCandidateQuery;
use Illuminate\Http\JsonResponse;

/**
 * listSenderPermissionCandidates (feature 004 FR-056): the helper list of the sender settings form.
 */
class SenderPermissionCandidateController extends Controller
{
    public function index(ListSenderPermissionCandidatesRequest $request, PermissionCandidateQuery $candidates): JsonResponse
    {
        $page = $candidates->page(RequestContext::requirePrincipal($request), $request->mailbox(), $request->listQuery(), $request->types());

        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
