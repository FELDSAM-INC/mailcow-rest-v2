<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\SaveIdentityProviderRequest;
use App\Http\Requests\Api\V2\UpdateIdentityProviderRequest;
use App\Http\Resources\Api\V2\IdentityProviderResource;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Services\IdentityProvider\IdentityProviderConfigService;
use App\Services\IdentityProvider\IdentityProviderRepository;
use App\Support\Http\ProblemType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * The identity provider configuration (FR-001 to FR-017): administrators only, read-only keys may
 * read. The role is checked before anything is looked up.
 */
class IdentityProviderController extends Controller
{
    public function __construct(
        private readonly IdentityProviderRepository $repository,
        private readonly IdentityProviderConfigService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', IdentityProviderSetting::class);
        $config = $this->repository->current() ?? throw new ProblemException(ProblemType::IdentityProviderNotConfigured);

        return $this->single(IdentityProviderResource::fromStored($config));
    }

    public function save(SaveIdentityProviderRequest $request): JsonResponse
    {
        $type = $request->type() ?? throw new LogicException('The request validated the type.');
        $result = $this->service->save($type, $request->members(), $request->header('If-Match'), $request->header('If-None-Match'), RequestContext::requirePrincipal($request));
        $response = $this->single(IdentityProviderResource::fromStored($result['config']), $result['created'] ? 201 : 200);

        return $result['created'] ? $response->header('Location', '/api/v2/identity-provider') : $response;
    }

    public function update(UpdateIdentityProviderRequest $request): JsonResponse
    {
        $config = $this->service->change($request->patch(), $request->header('If-Match'), RequestContext::requirePrincipal($request));

        return $this->single(IdentityProviderResource::fromStored($config));
    }

    public function destroy(Request $request): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('delete', IdentityProviderSetting::class);
        $this->service->delete($request->header('If-Match'), $principal);

        return response()->noContent();
    }

    private function single(IdentityProviderResource $resource, int $status = 200): JsonResponse
    {
        $data = $resource->resource;

        return response()->json(['data' => $data], $status, ['ETag' => (string) $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
