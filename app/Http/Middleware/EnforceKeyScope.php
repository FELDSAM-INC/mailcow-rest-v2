<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Third step of the api.v2 pipeline (R8, FR-033): a read-only key may only use GET and HEAD.
 * Any other method answers `403 insufficient-key-scope` before every other authorisation step.
 * Not reported to fail2ban (FR-040).
 */
class EnforceKeyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = RequestContext::principal($request)?->apiKey;

        if ($key !== null && $key->accessLevel === 'readOnly' && ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            throw new ProblemException(ProblemType::InsufficientKeyScope);
        }

        return $next($request);
    }
}
