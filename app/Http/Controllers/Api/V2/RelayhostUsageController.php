<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListRelayhostUsageRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Services\Routing\RelayhostQuery;
use Illuminate\Http\JsonResponse;

/**
 * listRelayhostUsage (feature 009 FR-022): the domains and mailboxes assigned to a relayhost.
 */
class RelayhostUsageController extends Controller
{
    public function index(ListRelayhostUsageRequest $request, RelayhostQuery $query): JsonResponse
    {
        return RoutingResource::list($query->usage($request->relayhostId(), $request->listQuery(), $request->kind()));
    }
}
