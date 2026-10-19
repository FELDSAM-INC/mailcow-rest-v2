<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\AccountResource;
use App\Policies\DomainAdminPolicy;
use App\Services\Accounts\AccountRepresentation;
use App\Services\Accounts\DomainAdminAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * suspendDomainAdminSecondFactors (feature 007 FR-027): administrators, with a session or a
 * read-write key.
 */
class DomainAdminSecondFactorSuspensionController extends Controller
{
    public function store(Request $request, string $username, DomainAdminAccountService $service, AccountRepresentation $accounts, DomainAdminPolicy $policy): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal);
        $service->suspendSecondFactors($principal, $username, $request->header('If-Match'));

        return AccountResource::response($accounts->find(Role::DomainAdmin, $username) ?? throw new NotFoundHttpException('The domain administrator does not exist.'));
    }
}
