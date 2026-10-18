<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Config\MailcowConfig;
use App\Services\MailQueue\MailQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * purgeMailQueue (feature 011 FR-041, FR-042): one audit record; refused when the audit log cannot
 * be written before the action.
 */
class MailQueuePurgeController extends Controller
{
    public function store(Request $request, MailQueueService $queue, MailcowConfig $config): JsonResponse
    {
        set_time_limit($config->dockerTimeout() + 20);

        $result = $queue->whole(RequestContext::requirePrincipal($request), 'purge');

        return response()->json(['data' => ['action' => $result['action'], 'completedAt' => $result['completedAt']]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($result['auditRecorded']);
    }
}
