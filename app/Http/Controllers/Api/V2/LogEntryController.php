<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListLogEntriesRequest;
use App\Services\Logs\LegacyUiActivityLogSource;
use App\Services\Logs\LoginLogSource;
use App\Services\Logs\LogType;
use App\Services\Logs\RedisListLogSource;
use App\Support\Http\ProblemType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * The twelve log lists of feature 011 (FR-022 to FR-032), one action for all of them: the route
 * default `logType` names the log. Reads are not audited. An unavailable database answers
 * `503 temporary-failure` (an unavailable shared store does so through SharedStoreUnavailable).
 */
class LogEntryController extends Controller
{
    public function index(ListLogEntriesRequest $request, RedisListLogSource $redis, LegacyUiActivityLogSource $legacyUi, LoginLogSource $logins): JsonResponse
    {
        $query = $request->logQuery();

        try {
            $page = match ($query->type) {
                LogType::LegacyUiActivity => $legacyUi->page($query),
                LogType::Logins => $logins->page($query),
                default => $redis->page($query),
            };
        } catch (QueryException) {
            throw new ProblemException(ProblemType::TemporaryFailure, 'mailcow\'s database is unavailable; retry later.', ['dependency' => 'database'], ['Retry-After' => '5']);
        }

        return response()->json([
            'data' => $page->entries,
            'meta' => ['total' => $page->total, 'limit' => $query->limit, 'offset' => $query->offset],
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
