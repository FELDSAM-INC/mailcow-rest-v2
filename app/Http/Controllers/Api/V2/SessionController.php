<?php

namespace App\Http\Controllers\Api\V2;

use App\Audit\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceImpersonation;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\PasswordSignInRequest;
use App\Http\Resources\Api\V2\SessionResource;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ImpersonationAudit;
use App\Services\Auth\PasswordSignInService;
use App\Services\Auth\SessionManager;
use App\Services\Webmail\WebmailHandOffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The browser session (contract `Session`): read it, sign in with a password, sign out.
 */
class SessionController extends Controller
{
    /**
     * getSession: never fails for a missing or ended session, it reports `anonymous`.
     */
    public function show(Request $request): SessionResource
    {
        return SessionResource::fromSession($request->session());
    }

    /**
     * createSession (FR-001–FR-008): `201` with the new state, `active`, `restricted` or
     * `pendingSecondFactor`.
     */
    public function store(PasswordSignInRequest $request, PasswordSignInService $signIn): JsonResponse
    {
        $signIn->signIn($request, (string) $request->validated('username'), (string) $request->validated('password'), $request->role());

        return SessionResource::fromSession($request->session())
            ->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v2/session');
    }

    /**
     * deleteSession (FR-010): ends a live session in any state and audits the sign-out of a
     * signed-in account. Without a live session nothing changes.
     */
    public function destroy(Request $request, SessionManager $sessions, AuditLogger $audit, WebmailHandOffService $handOffs): Response
    {
        $response = response()->noContent();

        if (RequestContext::hasLiveSession($request)) {
            $session = $request->session();
            $principal = $sessions->principal($session);
            $handle = $sessions->handle($session);

            // Feature 005: the session's webmail hand-off ends with it, and its cookie is removed.
            if ($handle !== null && $handOffs->hasHandOff($handle)) {
                $response->headers->setCookie($handOffs->expiredCookie());
            }

            // Feature 007 (FR-051, FR-052): signing out during an impersonation ends the whole
            // session; the impersonation's end is audited first, as automatic when it had become
            // invalid.
            $impersonating = RequestContext::principal($request);

            if ($impersonating?->impersonator !== null) {
                $reason = $request->attributes->get(EnforceImpersonation::INVALID);
                app(ImpersonationAudit::class)->ended($impersonating, $reason === null ? 'signOut' : 'automatic', $reason);
            }

            if ($principal !== null) {
                DB::transaction(fn () => $audit->record(
                    AuditEventType::SignOut,
                    true,
                    $principal,
                    ['type' => 'session', 'id' => null, 'name' => null],
                    $principal->authMethod->value,
                    null,
                    [],
                ));
            }

            $sessions->end($session);
        }

        return $response;
    }
}
