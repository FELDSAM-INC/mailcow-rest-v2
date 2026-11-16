<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\RequestIdentitySyncRunRequest;
use App\Http\Requests\Api\V2\UpdateIdentitySyncRequest;
use App\Models\Mailcow\IdentityProviderSetting;
use App\Services\IdentityProvider\IdentitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class IdentitySyncController extends Controller
{
    public function __construct(private readonly IdentitySyncService $service) {}

    public function show(Request $request): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', IdentityProviderSetting::class);
        $data = $this->service->read();

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }

    public function update(UpdateIdentitySyncRequest $request): JsonResponse
    {
        $data = $this->service->update($request->patch(), $request->header('If-Match'), RequestContext::requirePrincipal($request));

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }

    public function storeRun(RequestIdentitySyncRunRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->requestRun($request->mode(), RequestContext::requirePrincipal($request))], 202);
    }

    public function link(Request $request, string $mailbox): JsonResponse
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('view', IdentityProviderSetting::class);

        return response()->json(['data' => $this->service->link($mailbox)]);
    }

    public function unlink(Request $request, string $mailbox): Response
    {
        $actor = RequestContext::requirePrincipal($request);
        Gate::forUser($actor)->authorize('delete', IdentityProviderSetting::class);
        $this->service->unlink($mailbox, $request->header('If-Match'), $actor);

        return response()->noContent();
    }
}
