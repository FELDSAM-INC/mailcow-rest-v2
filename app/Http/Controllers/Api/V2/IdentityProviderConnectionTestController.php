<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\TestIdentityProviderConnectionRequest;
use App\Services\IdentityProvider\ConnectionTester;
use App\Services\IdentityProvider\IdentityProviderConfig;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Services\IdentityProvider\SecretRetentionPolicy;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * testIdentityProviderConnection (FR-019): tests unsaved candidate settings; a failed connection is
 * a `200` that reports it. Audited.
 */
class IdentityProviderConnectionTestController extends Controller
{
    public function store(TestIdentityProviderConnectionRequest $request, ConnectionTester $tester, SecretRetentionPolicy $secrets, IdentityProviderRepository $repository): JsonResponse
    {
        $type = $request->type() ?? throw new LogicException('The request validated the type.');
        $candidate = IdentityProviderConfig::fromInput($type, $request->members());
        $secret = $secrets->resolve($repository->current(), $candidate, $request->sentSecret());
        $result = $tester->test($candidate, $secret, RequestContext::requirePrincipal($request));

        return response()->json(['data' => $result->toArray()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
