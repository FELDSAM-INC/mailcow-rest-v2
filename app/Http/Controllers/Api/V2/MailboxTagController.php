<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Models\Mailcow\Mailbox;
use App\Services\Mailboxes\MailboxTagUsage;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * listMailboxTags (feature 003 FR-066): the distinct tags in the caller's scope with their counts,
 * sorted alphabetically without regard to letter case, with `q` and paging.
 */
class MailboxTagController extends Controller
{
    public function index(Request $request, MailboxTagUsage $tags): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('viewTags', Mailbox::class);

        return response()->json($tags->page($principal, ListQuery::fromRequest($request, MailboxTagUsage::SORTS, 'name')), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
