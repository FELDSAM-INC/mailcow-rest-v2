<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Policies\PresetPolicy;
use App\Services\Presets\PresetCatalog;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * listPresets (feature 013 FR-061): rspamd presets for administrators, sieve presets for
 * administrators and domain administrators.
 */
class PresetController extends Controller
{
    public function index(Request $request, string $presetKind, PresetPolicy $policy, PresetCatalog $presets): JsonResponse
    {
        $policy->authorize(RequestContext::requirePrincipal($request), $presetKind);

        return response()->json($presets->list($presetKind, ListQuery::fromRequest($request, ['id' => 'id'], 'id')), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
