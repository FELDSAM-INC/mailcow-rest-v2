<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\SetSpamScoreRequest;
use App\Http\Resources\Api\V2\SpamScoreResource;
use App\Services\SpamPolicy\SpamScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * getMailboxSpamScore, setMailboxSpamScore and resetMailboxSpamScore (feature 006 FR-039 to FR-042).
 */
class SpamScoreController extends Controller
{
    public function show(Request $request, string $mailbox, SpamScoreService $scores): JsonResponse
    {
        return SpamScoreResource::response($scores->read(RequestContext::requirePrincipal($request), $mailbox));
    }

    public function update(SetSpamScoreRequest $request, string $mailbox, SpamScoreService $scores): JsonResponse
    {
        return SpamScoreResource::response($scores->set(RequestContext::requirePrincipal($request), $mailbox, $request->junkScore(), $request->rejectScore(), $request->header('If-Match')));
    }

    public function destroy(Request $request, string $mailbox, SpamScoreService $scores): Response
    {
        $scores->reset(RequestContext::requirePrincipal($request), $mailbox, $request->header('If-Match'));

        return response()->noContent();
    }
}
