<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListDomainAdminsRequest;
use App\Http\Requests\Api\V2\StoreDomainAdminRequest;
use App\Http\Requests\Api\V2\UpdateDomainAdminRequest;
use App\Http\Resources\Api\V2\AccountResource;
use App\Policies\DomainAdminPolicy;
use App\Services\Accounts\AccountQuery;
use App\Services\Accounts\AccountRepresentation;
use App\Services\Accounts\DomainAdminAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listDomainAdmins, getDomainAdmin, createDomainAdmin, updateDomainAdmin and deleteDomainAdmin
 * (feature 007 US1, US2). Administrators only, with a session or a key; the role is checked first
 * (`403`), then the account (`404`).
 */
class DomainAdminController extends Controller
{
    public function __construct(
        private readonly DomainAdminAccountService $service,
        private readonly AccountRepresentation $accounts,
        private readonly DomainAdminPolicy $policy,
    ) {}

    public function index(ListDomainAdminsRequest $request, AccountQuery $query): JsonResponse
    {
        return AccountResource::list($query->page(Role::DomainAdmin, $request->listQuery(), $request->filters()));
    }

    public function show(Request $request, string $username): JsonResponse
    {
        $this->policy->authorize(RequestContext::requirePrincipal($request));

        return AccountResource::response($this->found($username));
    }

    public function store(StoreDomainAdminRequest $request): JsonResponse
    {
        $username = $this->service->create(RequestContext::requirePrincipal($request), $request->values());

        return AccountResource::response($this->found($username), 201, '/api/v2/domain-admins/'.$username);
    }

    public function update(UpdateDomainAdminRequest $request, string $username): JsonResponse
    {
        $username = $this->service->update(RequestContext::requirePrincipal($request), $username, $request->values(), $request->header('If-Match'), $request->hasSession() ? $request->session() : null);

        return AccountResource::response($this->found($username));
    }

    public function destroy(Request $request, string $username): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->policy->authorize($principal);
        $this->service->delete($principal, $username, $request->header('If-Match'));

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function found(string $username): array
    {
        return $this->accounts->find(Role::DomainAdmin, $username) ?? throw new NotFoundHttpException('The domain administrator does not exist.');
    }
}
