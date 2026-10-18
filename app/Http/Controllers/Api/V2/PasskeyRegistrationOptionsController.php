<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Passkeys\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * createPasskeyRegistrationOptions (FR-052): single-use registration options bound to the
 * session. A ceremony request: not audited.
 */
class PasskeyRegistrationOptionsController extends Controller
{
    public function store(Request $request, PasskeyService $passkeys): JsonResponse
    {
        $options = $passkeys->issueRegistrationOptions($request, RequestContext::requirePrincipal($request));

        return response()->json(['data' => [
            'publicKey' => $options['publicKey'],
            'expiresAt' => $options['expiresAt']->utc()->format('Y-m-d\TH:i:s\Z'),
        ]], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
