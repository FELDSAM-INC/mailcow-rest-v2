<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Config\MailcowConfig;
use App\Services\Monitoring\ContainerControlService;
use App\Services\Monitoring\ContainerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * restartContainer (feature 011 FR-013 to FR-016): synchronous, bounded by DOCKER_TIMEOUT; one audit
 * record whatever the outcome.
 */
class ContainerRestartController extends Controller
{
    public function store(Request $request, string $containerName, ContainerControlService $control, ContainerPresenter $presenter, MailcowConfig $config): JsonResponse
    {
        set_time_limit($config->dockerTimeout() + 20);

        $outcome = $control->perform(RequestContext::requirePrincipal($request), 'restart', $containerName);

        return response()->json(['data' => [
            'action' => $outcome->action,
            'outcome' => $outcome->outcome,
            'container' => $presenter->present($outcome->container, $outcome->collectedAt),
        ]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($outcome->auditRecorded);
    }
}
