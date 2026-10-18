<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\RoutingBulkActionRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Services\Routing\RelayhostService;
use App\Services\Routing\RoutingBulkActions;
use App\Services\Routing\RoutingBulkHandler;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * The bulk actions of feature 009 (FR-090, FR-091): applyRelayhostBulkAction,
 * applyTransportBulkAction, applyBccMapBulkAction, applyRecipientMapBulkAction,
 * applyTlsPolicyMapBulkAction and applyForwardingHostBulkAction, one result per distinct target,
 * status 200. The route's collection picks the item handler.
 */
class RoutingBulkActionController extends Controller
{
    /** @var array<string, class-string<RoutingBulkHandler>> collection => item handler */
    private const HANDLERS = [
        'relayhost' => RelayhostService::class,
    ];

    public function store(RoutingBulkActionRequest $request, RoutingBulkActions $bulk): JsonResponse
    {
        $handler = app()->make(self::HANDLERS[$request->collection()] ?? throw new LogicException('No bulk handler for '.$request->collection()));

        return RoutingResource::bulk($bulk->apply($request, RequestContext::requirePrincipal($request), $handler, $request->action(), $request->targets()));
    }
}
