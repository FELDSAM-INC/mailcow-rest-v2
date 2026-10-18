<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ListPasskeysRequest;
use App\Http\Requests\Api\V2\RegisterPasskeyRequest;
use App\Http\Requests\Api\V2\UpdatePasskeyRequest;
use App\Http\Resources\Api\V2\PasskeyResource;
use App\Models\Mailcow\Fido2Credential;
use App\Services\Passkeys\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The signed-in user's own passkeys (FR-052, FR-053). Other accounts' passkeys answer 404 for
 * every role.
 */
class PasskeyController extends Controller
{
    public function __construct(private readonly PasskeyService $passkeys) {}

    public function index(ListPasskeysRequest $request): JsonResponse
    {
        $page = $this->passkeys->list(RequestContext::requirePrincipal($request), $request->listQuery());

        return response()->json([
            'data' => array_map(fn (mixed $row): array => (new PasskeyResource($row))->toArray($request), $page['data']),
            'meta' => $page['meta'],
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, string $passkeyId): JsonResponse
    {
        $passkey = $this->passkeys->find(RequestContext::requirePrincipal($request), $passkeyId);

        if (! $passkey instanceof Fido2Credential) {
            throw new NotFoundHttpException('The passkey does not exist.');
        }

        return (new PasskeyResource($passkey))->withETag();
    }

    public function store(RegisterPasskeyRequest $request): JsonResponse
    {
        $passkey = $this->passkeys->register(
            $request,
            RequestContext::requirePrincipal($request),
            (array) $request->validated('credential'),
            $request->name(),
        );

        return (new PasskeyResource($passkey))
            ->withETag(201)
            ->header('Location', '/api/v2/me/passkeys/'.$passkey->getAttribute('cid'));
    }

    public function update(UpdatePasskeyRequest $request, string $passkeyId): JsonResponse
    {
        $passkey = $this->passkeys->rename($request, RequestContext::requirePrincipal($request), $passkeyId, (string) $request->validated('name'));

        return (new PasskeyResource($passkey))->withETag();
    }

    public function destroy(Request $request, string $passkeyId): Response
    {
        $this->passkeys->remove($request, RequestContext::requirePrincipal($request), $passkeyId);

        return response()->noContent();
    }
}
