<?php

namespace App\Http\Middleware;

use App\Auth\ImpersonationEndReason;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\Auth\ImpersonationService;
use App\Services\Auth\SessionManager;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature 007 (FR-046, FR-052, research R17), in the `api.v2` pipeline right after
 * EnsureSessionIntegrity: during an impersonation, every request of the session is validated and,
 * while valid, acts as the impersonated account (the request's principal becomes the target with
 * the impersonator attached).
 *
 * An impersonation that became invalid ends at once: it is audited (`endedBy: automatic` with the
 * reason), its identifier and anti-forgery token are renewed, and the request is refused with
 * `403 impersonation-ended` and not carried out, the session acting as the impersonator again.
 * Ending the impersonation and signing out are the exceptions: they continue to their controllers,
 * which end it the same way and complete normally (the reason is in the request's attributes).
 */
class EnforceImpersonation
{
    /** Request attribute: the reason of an invalid impersonation that the controller ends. */
    public const INVALID = 'restv2.impersonationInvalid';

    /** Routes that end and audit an invalid impersonation themselves and complete normally. */
    private const ENDING_ROUTES = ['impersonation.destroy', 'session.destroy'];

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ImpersonationService $impersonations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = RequestContext::principal($request);

        if ($principal === null || RequestContext::isApiKeyRequest($request) || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $impersonation = $this->sessions->impersonation($session);

        if ($impersonation === null) {
            return $next($request);
        }

        $impersonating = SessionManager::impersonating($principal, $impersonation);
        $reason = $this->impersonations->validate($impersonating, $impersonation['target']['generation']);
        RequestContext::setPrincipal($request, $impersonating);

        if ($reason === null) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ENDING_ROUTES, true)) {
            $request->attributes->set(self::INVALID, $reason);

            return $next($request);
        }

        $this->impersonations->end($session, $impersonating, 'automatic', $reason);
        RequestContext::setPrincipal($request, $principal);

        throw new ProblemException(ProblemType::ImpersonationEnded, self::detail($reason));
    }

    private static function detail(ImpersonationEndReason $reason): string
    {
        return match ($reason) {
            ImpersonationEndReason::TargetDeletedOrRenamed => 'The impersonated account was deleted or renamed. The request was not carried out; the session acts as the impersonator again.',
            ImpersonationEndReason::TargetOutOfScope => 'The impersonated mailbox is no longer in your domains. The request was not carried out; the session acts as the impersonator again.',
            ImpersonationEndReason::LoginAsRevoked => 'The loginAs permission was removed. The request was not carried out; the session acts as the impersonator again.',
        };
    }
}
