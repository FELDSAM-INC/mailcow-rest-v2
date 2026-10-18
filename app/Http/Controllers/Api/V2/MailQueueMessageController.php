<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListMailQueueMessagesRequest;
use App\Services\MailQueue\MailQueueService;
use Illuminate\Http\JsonResponse;

/**
 * listMailQueueMessages (feature 011 FR-037, FR-038, FR-043): the queued messages with the queue
 * counts.
 */
class MailQueueMessageController extends Controller
{
    public function index(ListMailQueueMessagesRequest $request, MailQueueService $queue): JsonResponse
    {
        return response()->json($queue->list($request->mailQueueQuery()), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
