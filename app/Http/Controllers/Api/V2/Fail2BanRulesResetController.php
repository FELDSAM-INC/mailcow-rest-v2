<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Config\MailcowConfig;
use App\Services\Fail2Ban\Fail2BanRulesService;
use App\Support\Runtime\TimeLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * resetFail2BanRules (feature 011 FR-051, FR-064): removes the rules, restarts the ban service and
 * waits up to 10 seconds for mailcow's defaults.
 */
class Fail2BanRulesResetController extends Controller
{
    public function store(Request $request, Fail2BanRulesService $rules, MailcowConfig $config): JsonResponse
    {
        TimeLimit::raise($config->dockerTimeout() + 20);

        $result = $rules->reset(RequestContext::requirePrincipal($request), $request->header('If-Match'));

        return response()->json(['data' => $result['data']], 200, ['ETag' => $result['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($result['auditRecorded']);
    }
}
