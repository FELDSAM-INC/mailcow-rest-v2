<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\MailboxBulkActionRequest;
use App\Services\Mailboxes\MailboxBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyMailboxBulkAction (feature 003 FR-054, FR-055): one result per distinct mailbox, status 200.
 */
class MailboxBulkActionController extends Controller
{
    public function store(MailboxBulkActionRequest $request, MailboxBulkActions $bulk): JsonResponse
    {
        // Deletions call the mail store; a client going away never interrupts the sequence.
        ignore_user_abort(true);
        set_time_limit(0);

        $protocol = $request->isProtocolAction() ? (string) $request->validated('protocol') : null;
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), (string) $request->validated('action'), $protocol, $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
