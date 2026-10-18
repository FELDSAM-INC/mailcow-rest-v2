<?php

namespace App\Http\Middleware;

use App\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The last route middleware of every feature 011 route (research R26): the gate
 * `operate-monitoring` for the request's principal, before any validation or lookup, so that a
 * refusal (`403 forbidden`) reveals nothing. Laravel's `can:` middleware would ask the session
 * guard's user, which this API does not use.
 */
class AuthorizeMonitoring
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::forUser(RequestContext::requirePrincipal($request))->authorize('operate-monitoring');

        return $next($request);
    }
}
