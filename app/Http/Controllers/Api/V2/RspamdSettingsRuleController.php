<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\ListRspamdSettingsRulesRequest;
use App\Http\Requests\Api\V2\Settings\StoreRspamdSettingsRuleRequest;
use App\Http\Requests\Api\V2\Settings\UpdateRspamdSettingsRuleRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Rspamd\RspamdSettingsRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listRspamdSettingsRules, createRspamdSettingsRule, getRspamdSettingsRule,
 * updateRspamdSettingsRule and deleteRspamdSettingsRule (feature 013 FR-050 to FR-055, FR-093).
 * Administrators only.
 */
class RspamdSettingsRuleController extends Controller
{
    public function index(ListRspamdSettingsRulesRequest $request, RspamdSettingsRuleService $rules): JsonResponse
    {
        return response()->json($rules->list($request->listQuery(), $request->active()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function store(StoreRspamdSettingsRuleRequest $request, RspamdSettingsRuleService $rules): JsonResponse
    {
        if ($request->validateOnly()) {
            return SettingsResource::valid();
        }

        $rule = $rules->create($request->values(), RequestContext::requirePrincipal($request));

        return SettingsResource::response($rule, 201, null, ['Location' => '/api/v2/settings/rspamd-rules/'.$rule['id']]);
    }

    public function show(Request $request, int $ruleId, SettingsPolicy $policy, RspamdSettingsRuleService $rules): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($rules->find($ruleId) ?? throw new NotFoundHttpException('The settings rule does not exist.'));
    }

    public function update(UpdateRspamdSettingsRuleRequest $request, int $ruleId, RspamdSettingsRuleService $rules): JsonResponse
    {
        $rule = $rules->update($ruleId, $request->patch(), $request->header('If-Match'), RequestContext::requirePrincipal($request), $request->validateOnly());

        return $rule === null ? SettingsResource::valid() : SettingsResource::response($rule);
    }

    public function destroy(Request $request, int $ruleId, SettingsPolicy $policy, RspamdSettingsRuleService $rules): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->requireAdmin($principal);
        $rules->delete($ruleId, $request->header('If-Match'), $principal);

        return response()->noContent();
    }
}
