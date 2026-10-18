<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\Auth\SessionManager;
use App\Services\IdentityProvider\IdentityProviderSessionGuard;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `restv2.auth:<mode>` (R8):
 *
 *  - `public`: anyone, API keys included;
 *  - `browser`: anonymous or signed-in browser sessions; a key gets `403 session-required`;
 *  - `session`: a signed-in session only; none gives `401 unauthenticated`, a pending sign-in
 *    `401 second-factor-required`, a key `403 session-required`;
 *  - `authenticated`: a signed-in session or an API key.
 *
 * Feature 012: in the modes `session` and `authenticated`, a single sign-on session is checked and,
 * when due, renewed with the provider (IdentityProviderSessionGuard); `GET /session`, sign-out and
 * public routes never depend on the provider.
 */
class RequireAuth
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly IdentityProviderSessionGuard $identityProviderSessions,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'session'): Response
    {
        $isKey = RequestContext::isApiKeyRequest($request);

        match ($mode) {
            'public' => null,
            'browser' => $isKey ? throw new ProblemException(ProblemType::SessionRequired) : null,
            'session' => $isKey ? throw new ProblemException(ProblemType::SessionRequired) : $this->requireSignedIn($request),
            'authenticated' => $isKey ? null : $this->requireSignedIn($request),
            default => throw new InvalidArgumentException("Unknown restv2.auth mode {$mode}."),
        };

        return $next($request);
    }

    private function requireSignedIn(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ProblemException(ProblemType::Unauthenticated);
        }

        $session = $request->session();
        $principal = $this->sessions->principal($session);

        if ($principal !== null) {
            $this->identityProviderSessions->check($request, $principal);

            return;
        }

        throw new ProblemException($this->sessions->pending($session) !== null
            ? ProblemType::SecondFactorRequired
            : ProblemType::Unauthenticated);
    }
}
