<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListRelayhostChoicesRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Services\Routing\RelayhostQuery;
use Illuminate\Http\JsonResponse;

/**
 * listRelayhostChoices (feature 009 FR-023, G41): identifier, host, username and active state of
 * every relayhost, for the relayhost selectors.
 */
class RelayhostChoiceController extends Controller
{
    public function index(ListRelayhostChoicesRequest $request, RelayhostQuery $query): JsonResponse
    {
        return RoutingResource::list($query->choices($request->listQuery()));
    }
}
