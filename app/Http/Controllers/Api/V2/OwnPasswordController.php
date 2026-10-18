<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ChangeOwnPasswordRequest;
use App\Services\Account\OwnPasswordService;
use Illuminate\Http\JsonResponse;

/**
 * changeOwnPassword (feature 005 FR-008–FR-015): every role, sessions only; also in a session
 * restricted by a forced password change, which it resolves.
 */
class OwnPasswordController extends Controller
{
    public function update(ChangeOwnPasswordRequest $request, OwnPasswordService $passwords): JsonResponse
    {
        $result = $passwords->change(
            $request,
            RequestContext::requirePrincipal($request),
            $request->currentPassword(),
            $request->newPassword(),
            $request->endOtherSessions(),
        );

        return response()->json(['data' => $result]);
    }
}
