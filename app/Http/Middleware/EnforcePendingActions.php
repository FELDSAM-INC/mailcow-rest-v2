<?php

namespace App\Http\Middleware;

use App\Auth\PendingAction;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\Auth\PendingActionEvaluator;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `restv2.pending:<mode>` (FR-014, R8). A restricted session answers
 * `403 pending-action-required` with `pendingActions`, except in mode `allow` (public and browser
 * routes, `GET /me`, sign-out) and in mode `secondFactorSetup` when that is the sole pending action
 * (passkey registration). Mode `includes,<action>` (feature 005 research R5) passes when the
 * session's pending actions include that action, whatever else is pending, so that both forced
 * actions can be resolved in either order. The default mode is `enforce`. API keys are never
 * restricted.
 */
class EnforcePendingActions
{
    public function __construct(private readonly PendingActionEvaluator $evaluator) {}

    public function handle(Request $request, Closure $next, string $mode = 'enforce', ?string $action = null): Response
    {
        $principal = RequestContext::principal($request);

        if ($mode === 'allow' || $principal === null || RequestContext::isApiKeyRequest($request)) {
            return $next($request);
        }

        $actions = $this->evaluator->evaluate($principal);

        if ($actions === [] || ($mode === 'secondFactorSetup' && $actions === [PendingAction::SecondFactorSetup])) {
            return $next($request);
        }

        if ($mode === 'includes' && in_array(PendingAction::tryFrom((string) $action), $actions, true)) {
            return $next($request);
        }

        throw new ProblemException(
            ProblemType::PendingActionRequired,
            null,
            ['pendingActions' => array_map(fn (PendingAction $a): string => $a->value, $actions)],
        );
    }
}
