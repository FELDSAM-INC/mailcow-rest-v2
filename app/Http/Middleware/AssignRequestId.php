<?php

namespace App\Http\Middleware;

use App\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First step of the api.v2 pipeline (R8, R24): resolves the request ID from a valid W3C
 * `traceparent`, or creates one, for the log context, problem details and audit records.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::requestId($request);

        return $next($request);
    }
}
