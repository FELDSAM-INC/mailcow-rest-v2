<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The pending sign-in (FR-024). Cancelling it is a ceremony request: not audited (plan,
 * Complexity Tracking).
 */
class PendingSignInController extends Controller
{
    /**
     * cancelPendingSignIn: the session returns to `anonymous` and keeps its anti-forgery token.
     */
    public function destroy(Request $request, SessionManager $sessions): Response
    {
        $sessions->cancelPending($request->session());

        return response()->noContent();
    }
}
