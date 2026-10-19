<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\ReplaceRspamdRegexMapRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Rspamd\RspamdRegexMapService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listRspamdRegexMaps, getRspamdRegexMap and replaceRspamdRegexMap (feature 013 FR-056 to FR-059,
 * FR-093; gap G17: the list returns summaries). Administrators only.
 */
class RspamdRegexMapController extends Controller
{
    public function index(Request $request, SettingsPolicy $policy, RspamdRegexMapService $maps): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));
        $query = ListQuery::fromRequest($request, ['name' => 'name', 'id' => 'id'], 'name');

        return response()->json($maps->list($query, $request->query('sort') !== null), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $mapId, SettingsPolicy $policy, RspamdRegexMapService $maps): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($maps->find($mapId) ?? throw new NotFoundHttpException('The regex map does not exist.'));
    }

    public function update(ReplaceRspamdRegexMapRequest $request, string $mapId, RspamdRegexMapService $maps): JsonResponse
    {
        $map = $maps->replace($mapId, $request->content(), $request->header('If-Match'), RequestContext::requirePrincipal($request), $request->validateOnly());

        return $map === null ? SettingsResource::valid() : SettingsResource::response($map);
    }
}
