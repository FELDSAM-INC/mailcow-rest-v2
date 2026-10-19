<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\AccountResource;
use App\Policies\AdminPolicy;
use App\Services\Accounts\AccountRepresentation;
use App\Services\Accounts\AdminAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * suspendAdminSecondFactors (feature 007 FR-027): only a signed-in administrator.
 */
class AdminSecondFactorSuspensionController extends Controller
{
    public function store(Request $request, string $username, AdminAccountService $service, AccountRepresentation $accounts, AdminPolicy $policy): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, AdminPolicy::WRITE);
        $service->suspendSecondFactors($principal, $username, $request->header('If-Match'));

        return AccountResource::response($accounts->find(Role::Admin, $username) ?? throw new NotFoundHttpException('The administrator does not exist.'));
    }
}
