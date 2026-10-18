<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\DomainTemplateBulkActionRequest;
use App\Services\DomainTemplates\DomainTemplateBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyDomainTemplateBulkAction (gap G40): one result per distinct domain template, status 200.
 */
class DomainTemplateBulkActionController extends Controller
{
    public function store(DomainTemplateBulkActionRequest $request, DomainTemplateBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
