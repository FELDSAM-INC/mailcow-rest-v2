<?php

namespace App\Http\Middleware;

use App\Http\RequestContext;
use App\Services\Auth\AccountResolver;
use App\Services\Auth\SessionManager;
use App\Services\Config\MailcowConfig;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a browser session (R6, R8) that
 *
 *  - was idle longer than mailcow's SESSION_LIFETIME (FR-009, checked to the second),
 *  - is used with another User-Agent than the one it signed in with (FR-012), or
 *  - belongs to an account that is deleted, deactivated or changed role (FR-029).
 *
 * The request then continues as anonymous with a new session and token. Every request renews the
 * idle timer. The signed-in principal is put into the request context.
 */
class EnsureSessionIntegrity
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly AccountResolver $accounts,
        private readonly MailcowConfig $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (RequestContext::isApiKeyRequest($request) || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $lastActivity = $this->sessions->lastActivity($session);
        $boundUserAgent = $this->sessions->boundUserAgentHash($session);
        $principal = $this->sessions->principal($session);

        $expired = $lastActivity !== null && CarbonImmutable::now()->getTimestamp() - $lastActivity > $this->config->sessionLifetime();
        $otherAgent = $boundUserAgent !== null && ! hash_equals($boundUserAgent, SessionManager::userAgentHash($request->userAgent()));

        if ($expired || $otherAgent || ($principal !== null && ! $this->accounts->accountStillActive($principal))) {
            $this->sessions->end($session);
            RequestContext::setLiveSession($request, false);
            $principal = null;
        }

        $this->sessions->touch($session);

        if ($principal !== null) {
            RequestContext::setPrincipal($request, $principal);
        }

        return $next($request);
    }
}
