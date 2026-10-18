<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ReplaceFail2BanRulesRequest;
use App\Services\Fail2Ban\Fail2BanRulesService;
use Illuminate\Http\JsonResponse;

/**
 * getFail2BanRules and replaceFail2BanRules (feature 011 FR-049, FR-050, FR-057).
 */
class Fail2BanRulesController extends Controller
{
    public function show(Fail2BanRulesService $rules): JsonResponse
    {
        $result = $rules->read();

        return response()->json(['data' => $result['data']], 200, ['ETag' => $result['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function update(ReplaceFail2BanRulesRequest $request, Fail2BanRulesService $rules): JsonResponse
    {
        $result = $rules->replace(RequestContext::requirePrincipal($request), $request->patterns(), $request->header('If-Match'));

        return response()->json(['data' => $result['data']], 200, ['ETag' => $result['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($result['auditRecorded']);
    }
}
