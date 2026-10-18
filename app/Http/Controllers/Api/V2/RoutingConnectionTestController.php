<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\RoutingConnectionTestRequest;
use App\Http\Resources\Api\V2\RoutingResource;
use App\Services\Routing\ConnectionTestService;
use App\Services\Routing\RelayhostQuery;
use App\Services\Routing\TransportQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * testRelayhostConnection and testTransportConnection (feature 009 US4): a test that ran answers
 * `200` whatever its outcome.
 */
class RoutingConnectionTestController extends Controller
{
    public function relayhost(RoutingConnectionTestRequest $request, string $relayhostId, RelayhostQuery $query, ConnectionTestService $tests): JsonResponse
    {
        $id = $query->resolve($relayhostId) ?? throw new NotFoundHttpException('The relayhost does not exist.');

        return RoutingResource::response($tests->testRelayhost(RequestContext::requirePrincipal($request), $id, $request->sender(), $request->recipient()));
    }

    public function transport(RoutingConnectionTestRequest $request, string $transportId, TransportQuery $query, ConnectionTestService $tests): JsonResponse
    {
        $id = $query->resolve($transportId) ?? throw new NotFoundHttpException('The transport does not exist.');

        return RoutingResource::response($tests->testTransport(RequestContext::requirePrincipal($request), $id, $request->sender(), $request->recipient()));
    }
}
