<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Oauth2ClientBulkActionRequest;
use App\Services\Oauth2\Oauth2ClientBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * bulkOauth2ClientAction (FR-060, FR-061): one result per distinct client, status 200.
 */
class Oauth2ClientBulkActionController extends Controller
{
    public function store(Oauth2ClientBulkActionRequest $request, Oauth2ClientBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), (string) $request->validated('action'), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
