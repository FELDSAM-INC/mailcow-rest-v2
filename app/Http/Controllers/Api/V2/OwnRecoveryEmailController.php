<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ReplaceOwnRecoveryEmailRequest;
use App\Services\Account\RecoveryEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getOwnRecoveryEmail and replaceOwnRecoveryEmail (feature 005 FR-018): the signed-in mailbox user's
 * recovery address, with its version tag.
 */
class OwnRecoveryEmailController extends Controller
{
    public function __construct(private readonly RecoveryEmailService $recovery) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->recovery->read(RequestContext::requirePrincipal($request)));
    }

    public function update(ReplaceOwnRecoveryEmailRequest $request): JsonResponse
    {
        return $this->respond($this->recovery->replace($request, RequestContext::requirePrincipal($request), $request->address(), $request->currentPassword()));
    }

    /**
     * @param  array{address: string|null, etag: string}  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
