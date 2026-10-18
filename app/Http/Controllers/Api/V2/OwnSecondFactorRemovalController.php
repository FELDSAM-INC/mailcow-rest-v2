<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\RemoveAllOwnSecondFactorsRequest;
use App\Services\SecondFactors\SecondFactorRemovalService;
use Illuminate\Http\Response;

/**
 * removeAllOwnSecondFactors (feature 005 FR-035): every own entry, with the current password;
 * passkeys stay.
 */
class OwnSecondFactorRemovalController extends Controller
{
    public function store(RemoveAllOwnSecondFactorsRequest $request, SecondFactorRemovalService $removal): Response
    {
        $removal->removeAll($request, RequestContext::requirePrincipal($request), $request->currentPassword());

        return response()->noContent();
    }
}
