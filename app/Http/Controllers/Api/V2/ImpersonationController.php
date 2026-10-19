<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceImpersonation;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\StartImpersonationRequest;
use App\Http\Resources\Api\V2\IdentityResource;
use App\Http\Resources\Api\V2\ImpersonationResource;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * getImpersonation, startImpersonation and endImpersonation (feature 007 US3): the session's
 * impersonation. Signed-in browser sessions only; reading and ending also in restricted sessions.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonations) {}

    public function show(Request $request): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->impersonator === null) {
            throw new NotFoundHttpException('The session is not impersonating an account.');
        }

        return response()->json(['data' => ImpersonationResource::present($principal)], 200, [], JSON_UNESCAPED_SLASHES);
    }

    public function store(StartImpersonationRequest $request): JsonResponse
    {
        $impersonating = $this->impersonations->start($request->session(), RequestContext::requirePrincipal($request), $request->role(), $request->username());
        RequestContext::setPrincipal($request, $impersonating);

        $data = ImpersonationResource::present($impersonating) + [
            'identity' => (new IdentityResource($impersonating))->toArray($request),
            'csrfToken' => ['token' => $request->session()->token(), 'headerName' => 'X-CSRF-Token'],
        ];

        return response()->json(['data' => $data], 201, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Location', '/api/v2/session/impersonation')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Without an impersonation nothing changes (`204`, not audited). An impersonation that became
     * invalid is ended and audited as automatic, and the answer is the same `204` (FR-052).
     */
    public function destroy(Request $request): Response
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->impersonator !== null) {
            $reason = $request->attributes->get(EnforceImpersonation::INVALID);
            $this->impersonations->end($request->session(), $principal, $reason === null ? 'explicit' : 'automatic', $reason);
            RequestContext::setPrincipal($request, $principal->impersonator);
        }

        return response()->noContent();
    }
}
