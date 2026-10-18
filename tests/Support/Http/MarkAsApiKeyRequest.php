<?php

namespace Tests\Support\Http;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test middleware that stands in for AuthenticateApiKey (T088): it marks the request as
 * authenticated by an API key of the given owner (`MarkAsApiKeyRequest::class.':reseller,domainAdmin'`).
 */
class MarkAsApiKeyRequest
{
    public function handle(Request $request, Closure $next, string $username = 'admin', string $role = 'admin'): Response
    {
        RequestContext::setApiKeyPrincipal($request, new Principal(
            username: $username,
            role: Role::from($role),
            accountKind: AccountKind::Admin,
            authMethod: AuthMethod::ApiKey,
        ));

        return $next($request);
    }
}
