<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\MailQueueBulkActionRequest;
use App\Services\Config\MailcowConfig;
use App\Services\MailQueue\MailQueueService;
use App\Support\Runtime\TimeLimit;
use Illuminate\Http\JsonResponse;

/**
 * bulkMailQueueMessages (feature 011 FR-040, FR-042): deliver, hold, release or delete for up to
 * 1,000 queue IDs, one result per distinct ID and one audit record per request.
 */
class MailQueueBulkActionController extends Controller
{
    public function store(MailQueueBulkActionRequest $request, MailQueueService $queue, MailcowConfig $config): JsonResponse
    {
        TimeLimit::raise($config->dockerTimeout() + 20);

        /** @var 'deliver'|'hold'|'release'|'delete' $action */
        $action = (string) $request->validated('action');
        $outcome = $queue->apply(RequestContext::requirePrincipal($request), $action, $request->ids());

        return response()->json(['data' => $outcome->result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)->withAuditRecorded($outcome->auditRecorded);
    }
}
