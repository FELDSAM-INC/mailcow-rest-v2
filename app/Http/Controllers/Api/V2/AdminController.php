<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListAdminsRequest;
use App\Http\Requests\Api\V2\StoreAdminRequest;
use App\Http\Requests\Api\V2\UpdateAdminRequest;
use App\Http\Resources\Api\V2\AccountResource;
use App\Policies\AdminPolicy;
use App\Services\Accounts\AccountQuery;
use App\Services\Accounts\AccountRepresentation;
use App\Services\Accounts\AdminAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listAdmins, getAdmin, createAdmin, updateAdmin and deleteAdmin (feature 007 US4). Reads with a
 * session or an administrator's key; writes only in a signed-in administrator session. The role is
 * checked first (`403`), then the account (`404`).
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly AdminAccountService $service,
        private readonly AccountRepresentation $accounts,
        private readonly AdminPolicy $policy,
    ) {}

    public function index(ListAdminsRequest $request, AccountQuery $query): JsonResponse
    {
        return AccountResource::list($query->page(Role::Admin, $request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $username): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request), AdminPolicy::VIEW);

        return AccountResource::response($this->found($username));
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        $username = $this->service->create(RequestContext::requirePrincipal($request), $request->values());

        return AccountResource::response($this->found($username), 201, '/api/v2/admins/'.$username);
    }

    public function update(UpdateAdminRequest $request, string $username): JsonResponse
    {
        $username = $this->service->update(RequestContext::requirePrincipal($request), $username, $request->values(), $request->header('If-Match'), $request->hasSession() ? $request->session() : null);

        return AccountResource::response($this->found($username));
    }

    public function destroy(Request $request, string $username): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal, AdminPolicy::WRITE);
        $this->service->delete($principal, $username, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(string $username): array
    {
        return $this->accounts->find(Role::Admin, $username) ?? throw new NotFoundHttpException('The administrator does not exist.');
    }
}
