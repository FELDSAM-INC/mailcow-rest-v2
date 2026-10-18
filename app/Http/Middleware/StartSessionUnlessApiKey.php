<?php

namespace App\Http\Middleware;

use App\Http\RequestContext;
use App\Services\Auth\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;

/**
 * Laravel's StartSession for the API (R6):
 *
 *  - requests authenticated by an API key never start a session (FR-038; the key is resolved
 *    before this middleware, T088);
 *  - routes in auth mode `public` (public configuration, health, contract, documentation) never
 *    use a session, so that signed-out page loads and monitors create none;
 *  - each request starts from a clean session store, so that data never leaks between requests
 *    handled by the same application instance;
 *  - no previous-URL bookkeeping;
 *  - it records whether the request arrived with a live session (one that exists in Redis), which
 *    `restv2.csrf:live` needs (R7: signing out without a live session needs no token).
 */
class StartSessionUnlessApiKey extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (RequestContext::isApiKeyRequest($request) || self::isPublicRoute($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private static function isPublicRoute(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && in_array('restv2.auth:public', $route->gatherMiddleware(), true);
    }

    public function getSession(Request $request)
    {
        return tap($this->manager->driver(), function ($session) use ($request): void {
            $session->flush();
            $session->setId($request->cookies->get($session->getName()));
        });
    }

    protected function startSession(Request $request, $session)
    {
        $session = parent::startSession($request, $session);

        // Every live session carries its last activity; a new one has only its token yet.
        RequestContext::setLiveSession($request, $session->has(SessionManager::LAST_ACTIVITY));

        return $session;
    }

    protected function storeCurrentUrl(Request $request, $session)
    {
        // Not used by the API.
    }
}
