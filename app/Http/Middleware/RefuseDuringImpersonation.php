<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `restv2.not-impersonating` (feature 007 FR-049, research R19): the operations
 * that manage the signed-in account's own sign-in credentials (password, passkeys, second factors,
 * recovery e-mail) and every webmail sign-in answer `403 not-allowed-while-impersonating` during an
 * impersonation, so that an impersonator can never plant credentials that outlive the permission.
 * App passwords follow the effective permission flags instead and are not guarded.
 */
class RefuseDuringImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (RequestContext::principal($request)?->impersonator !== null) {
            throw new ProblemException(ProblemType::NotAllowedWhileImpersonating);
        }

        return $next($request);
    }
}
