<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListRspamdHistoryRequest;
use App\Rules\Rfc3339DateTime;
use App\Services\Rspamd\RspamdHistoryService;
use Illuminate\Http\JsonResponse;

/**
 * listRspamdHistory (feature 011 FR-033, FR-034, FR-036).
 */
class RspamdHistoryController extends Controller
{
    public function index(ListRspamdHistoryRequest $request, RspamdHistoryService $history): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return response()->json($history->list(
            $request->listQuery(),
            $request->actions(),
            $request->score('minScore'),
            $request->score('maxScore'),
            is_string($from) ? Rfc3339DateTime::parse($from) : null,
            is_string($to) ? Rfc3339DateTime::parse($to) : null,
        ), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
