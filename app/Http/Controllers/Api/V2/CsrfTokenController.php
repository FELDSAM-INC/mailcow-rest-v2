<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getCsrfToken (FR-011, R7): the anti-forgery token of the caller's session; the session (and its
 * cookie) is started by the api.v2 pipeline when there is none. Not audited (ceremony request).
 */
class CsrfTokenController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()
            ->json(['data' => [
                'token' => $request->session()->token(),
                'headerName' => 'X-CSRF-Token',
            ]])
            ->header('Cache-Control', 'no-store');
    }
}
