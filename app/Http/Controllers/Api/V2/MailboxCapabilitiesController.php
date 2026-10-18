<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getMailboxCapabilities (feature 003 FR-064): administrators and domain administrators, read-only
 * keys included.
 */
class MailboxCapabilitiesController extends Controller
{
    public function show(Request $request, MailboxCapabilities $capabilities): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('viewCapabilities', Mailbox::class);

        return response()->json(['data' => $capabilities->for($principal)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
