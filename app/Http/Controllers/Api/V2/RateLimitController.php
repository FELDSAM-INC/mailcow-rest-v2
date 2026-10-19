<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListRateLimitsRequest;
use App\Http\Requests\Api\V2\SetRateLimitRequest;
use App\Http\Resources\Api\V2\RateLimitResource;
use App\Services\RateLimits\RateLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;

/**
 * The rate limit operations (feature 006 FR-050 to FR-054): `listRateLimits`, and one read, change
 * and removal per object kind, the kind from the route default `restv2.objectKind` and the object
 * from the path parameter of that kind.
 */
class RateLimitController extends Controller
{
    public function index(ListRateLimitsRequest $request, RateLimitService $limits): JsonResponse
    {
        return RateLimitResource::list($limits->page(RequestContext::requirePrincipal($request), $request->listQuery(), $request->kind()));
    }

    public function show(Request $request, RateLimitService $limits): JsonResponse
    {
        return RateLimitResource::response($limits->read(RequestContext::requirePrincipal($request), self::kind($request), self::object($request)));
    }

    public function update(SetRateLimitRequest $request, RateLimitService $limits): JsonResponse
    {
        return RateLimitResource::response($limits->set(RequestContext::requirePrincipal($request), self::kind($request), self::object($request), $request->limitValue(), $request->frame(), $request->header('If-Match')));
    }

    public function destroy(Request $request, RateLimitService $limits): Response
    {
        $limits->remove(RequestContext::requirePrincipal($request), self::kind($request), self::object($request), $request->header('If-Match'));

        return response()->noContent();
    }

    private static function kind(Request $request): string
    {
        $route = $request->route();

        return $route instanceof Route ? (string) ($route->defaults['restv2.objectKind'] ?? 'domain') : 'domain';
    }

    private static function object(Request $request): string
    {
        return (string) $request->route(self::kind($request));
    }
}
