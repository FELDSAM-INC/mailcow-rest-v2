<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Dkim\DkimNameResolver;
use App\Services\DnsDiagnostics\DiagnosticsRateLimiter;
use App\Services\DnsDiagnostics\DnsDiagnosticsService;
use App\Support\Http\NoStore;
use App\Support\Http\ProblemType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * runDnsDiagnostics (feature 010 US3, FR-039, FR-049, research R20). Order: the role (`403`), the
 * run limit (`429` with `Retry-After` and `RateLimit-*`, before any query), the name, a domain or
 * alias domain in the caller's scope (`404`, not counted), then the counted run, answered with
 * `RateLimit-*` and `Cache-Control: no-store`. Administrators get the server-wide checks.
 */
class DnsDiagnosticsController extends Controller
{
    public function show(Request $request, string $diagnosticsName, DiagnosticsRateLimiter $limiter, DkimNameResolver $names, DnsDiagnosticsService $diagnostics): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $gate = Gate::forUser($principal);
        $gate->authorize('dnsDiagnostics.run');

        self::requireCapacity($limiter->check($principal));

        $name = $names->resolve($principal, $diagnosticsName);

        if ($name === null || ! $name->isMailDomain()) {
            throw new NotFoundHttpException('The name is neither a domain nor an alias domain.');
        }

        $counted = $limiter->hit($principal);
        self::requireCapacity($counted);
        $report = $diagnostics->run($name, $gate->allows('dnsDiagnostics.serverChecks'), self::httpsPort($request));

        return NoStore::apply(response()->json(['data' => $report->toArray()], 200, self::headers($counted['limit'], $counted['remaining'], $counted['reset']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array{allowed: bool, limit: int, remaining: int, reset: int} $window */
    private static function requireCapacity(array $window): void
    {
        if (! $window['allowed']) {
            $retryAfter = max(1, $window['reset']);

            throw new ProblemException(
                ProblemType::TooManyRequests,
                "At most {$window['limit']} DNS diagnostics runs per minute are allowed.",
                ['retryAfter' => $retryAfter],
                ['Retry-After' => (string) $retryAfter] + self::headers($window['limit'], 0, $window['reset']),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private static function headers(int $limit, int $remaining, int $reset): array
    {
        return ['RateLimit-Limit' => (string) $limit, 'RateLimit-Remaining' => (string) $remaining, 'RateLimit-Reset' => (string) $reset];
    }

    /** The web interface's HTTPS port from the request's `Host`, else 443 (legacy). */
    private static function httpsPort(Request $request): int
    {
        return preg_match('/:(\d{1,5})$/', (string) $request->header('Host'), $m) === 1 && (int) $m[1] > 0 ? (int) $m[1] : 443;
    }
}
