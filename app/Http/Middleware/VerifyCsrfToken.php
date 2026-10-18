<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Anti-forgery check of session requests (FR-011, R7), route middleware `restv2.csrf`:
 *
 *  - every method except GET, HEAD and OPTIONS needs the session's token, whatever the content
 *    type, also in tests (Laravel skips the check in unit tests);
 *  - the token is read only from the `X-CSRF-Token` header, never from `_token` or `X-XSRF-TOKEN`;
 *  - `Sec-Fetch-Site: cross-site` is refused even with a valid token, while same-origin requests
 *    still need the token (Laravel would accept them without it);
 *  - no `XSRF-TOKEN` cookie is set;
 *  - requests authenticated by an API key are exempt;
 *  - with the parameter `live` (`restv2.csrf:live`) a request without a live session is exempt,
 *    so that signing out without a session is a no-op (R7).
 *
 * A failure answers `403 /api/v2/problems/csrf-token-mismatch`.
 */
class VerifyCsrfToken extends ValidateCsrfToken
{
    protected $addHttpCookie = false;

    public function handle($request, Closure $next, ?string $mode = null)
    {
        if ($this->isReading($request) || RequestContext::isApiKeyRequest($request)) {
            return $next($request);
        }

        if ($mode === 'live' && ! RequestContext::hasLiveSession($request)) {
            return $next($request);
        }

        if (strtolower((string) $request->headers->get('Sec-Fetch-Site')) === 'cross-site'
            || ! $request->hasSession()
            || ! $this->tokensMatch($request)) {
            throw new ProblemException(ProblemType::CsrfTokenMismatch);
        }

        return $next($request);
    }

    protected function getTokenFromRequest($request)
    {
        return $request->headers->get('X-CSRF-Token');
    }
}
