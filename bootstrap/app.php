<?php

use App\Clients\DockerApi\DockerApiUnreachable;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Exceptions\ProblemRenderer;
use App\Exceptions\ValidationProblemException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnforceKeyScope;
use App\Http\Middleware\EnforcePendingActions;
use App\Http\Middleware\EnsureSessionIntegrity;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\StartSessionUnlessApiKey;
use App\Http\Middleware\VerifyCsrfToken;
use App\Services\Audit\RefusalAuditor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The client address is nginx-mailcow's REMOTE_ADDR, which mailcow's real_ip module has
        // already resolved: no X-Forwarded-* header is trusted (R23).
        $middleware->trustProxies(at: []);

        // No CORS headers: the API is same-origin only, which is what keeps the header-only
        // anti-forgery token unforgeable (R7). Input is never trimmed or rewritten, passwords
        // included; usernames are normalised where legacy does it.
        $middleware->remove([HandleCors::class, TrimStrings::class, ConvertEmptyStringsToNull::class]);

        // Request pipeline of every /api/v2 route, in this order (R8): the key decides before the
        // session, and a read-only key is refused for unsafe methods before any other check.
        $middleware->group('api.v2', [
            AssignRequestId::class,
            AuthenticateApiKey::class,
            EnforceKeyScope::class,
            StartSessionUnlessApiKey::class,
            EnsureSessionIntegrity::class,
        ]);

        // Laravel sorts route middleware by its priority list, which hoists StartSession (and so
        // StartSessionUnlessApiKey) above unlisted middleware. The steps before it are listed in
        // front of it, so that the key is resolved before any session starts.
        foreach ([AssignRequestId::class, AuthenticateApiKey::class, EnforceKeyScope::class] as $step) {
            $middleware->prependToPriorityList(StartSession::class, $step);
        }

        // The routes of the signed-in account's own credentials and functions (feature 005 research
        // R5). Empty for now; feature 007 adds its "not while impersonating" refusal here.
        $middleware->group('restv2.own', []);

        // Per route, in this order: restv2.auth:<mode>, restv2.csrf, restv2.pending:<mode>.
        $middleware->alias([
            'restv2.auth' => RequireAuth::class,
            'restv2.csrf' => VerifyCsrfToken::class,
            'restv2.pending' => EnforcePendingActions::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ProblemException::class, ValidationProblemException::class, SharedStoreUnavailable::class, DockerApiUnreachable::class]);

        // Feature 010 (research R6): an imported DKIM private key never reaches the flashed input.
        $exceptions->dontFlash(['privateKey']);

        // Every error is an RFC 9457 problem (FR-049). Refusals on routes that declare
        // `restv2.audit` are audited (docs/conventions.md "Refusal auditing").
        $exceptions->render(function (Throwable $e, Request $request) {
            $response = ProblemRenderer::render($e, $request);
            app(RefusalAuditor::class)->recordResponse($request, $response, $e);

            return $response;
        });
    })->create();
