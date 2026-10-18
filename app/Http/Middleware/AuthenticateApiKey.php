<?php

namespace App\Http\Middleware;

use App\Http\RequestContext;
use App\Services\ApiKeys\ApiKeyAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second step of the api.v2 pipeline (R8, R19): a request with a non-empty `X-API-Key` header is
 * authenticated by that key, whatever else it carries (the key decides over a session cookie).
 * An invalid, expired or revoked key, or a disallowed address, ends the request with its problem
 * type and is reported to fail2ban. The request is then marked, so that it never starts a session
 * and needs no anti-forgery token (FR-038).
 *
 * The System routes (`system.*`: health, the contract, the documentation and the problem pages)
 * ignore the header.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $value = trim((string) $request->headers->get('X-API-Key', ''));

        if ($value === '' || $request->routeIs('system.*')) {
            return $next($request);
        }

        RequestContext::setApiKeyPrincipal($request, $this->authenticator->authenticate($value, (string) $request->ip()));

        return $next($request);
    }
}
