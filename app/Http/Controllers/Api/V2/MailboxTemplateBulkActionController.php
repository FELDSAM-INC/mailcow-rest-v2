<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\MailboxTemplateBulkActionRequest;
use App\Services\Mailboxes\MailboxTemplateBulkActions;
use Illuminate\Http\JsonResponse;

/**
 * applyMailboxTemplateBulkAction (feature 003 gap G39): one result per distinct mailbox template, status 200.
 */
class MailboxTemplateBulkActionController extends Controller
{
    public function store(MailboxTemplateBulkActionRequest $request, MailboxTemplateBulkActions $bulk): JsonResponse
    {
        $result = $bulk->apply($request, RequestContext::requirePrincipal($request), $request->targets());

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
