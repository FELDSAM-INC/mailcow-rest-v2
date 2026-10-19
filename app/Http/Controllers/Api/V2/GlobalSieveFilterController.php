<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\ReplaceGlobalSieveFilterRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\GlobalFilters\GlobalSieveFilterService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * listGlobalSieveFilters, getGlobalSieveFilter and replaceGlobalSieveFilter (feature 013 FR-062 to
 * FR-065, FR-093). Administrators only.
 */
class GlobalSieveFilterController extends Controller
{
    public function index(Request $request, SettingsPolicy $policy, GlobalSieveFilterService $filters): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));
        $query = ListQuery::fromRequest($request, ['id' => 'id'], 'id');
        $all = $filters->all();

        return response()->json(['data' => array_slice($all, $query->offset, $query->limit), 'meta' => $query->meta(count($all))], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $filterId, SettingsPolicy $policy, GlobalSieveFilterService $filters): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($filters->find($filterId));
    }

    public function update(ReplaceGlobalSieveFilterRequest $request, string $filterId, GlobalSieveFilterService $filters): JsonResponse
    {
        $result = $filters->replace($filterId, $request->script(), $request->deferRestart(), $request->header('If-Match'), RequestContext::requirePrincipal($request), $request->validateOnly());

        return $result === null ? SettingsResource::valid() : SettingsResource::response($result['filter'], 200, ['dovecotRestart' => $result['restart']]);
    }
}
