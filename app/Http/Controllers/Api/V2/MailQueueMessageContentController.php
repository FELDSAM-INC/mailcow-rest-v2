<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\MailQueue\MailQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getMailQueueMessageContent (feature 011 FR-039, FR-062): a queued message as Postfix shows it;
 * every view is audited, never with the content.
 */
class MailQueueMessageContentController extends Controller
{
    public function show(Request $request, string $queueId, MailQueueService $queue): JsonResponse
    {
        return response()->json(['data' => $queue->content(RequestContext::requirePrincipal($request), $queueId)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
