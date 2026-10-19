<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\SetRspamdUiPasswordRequest;
use App\Services\Rspamd\RspamdUiPasswordService;
use Illuminate\Http\Response;

/**
 * setRspamdUiPassword (feature 013 FR-066, FR-067): a signed-in administrator only (keys get
 * `session-required` from the route mode).
 */
class RspamdUiPasswordController extends Controller
{
    public function update(SetRspamdUiPasswordRequest $request, RspamdUiPasswordService $service): Response
    {
        $service->change($request->password(), RequestContext::requirePrincipal($request));

        return response()->noContent();
    }
}
