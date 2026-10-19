<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Webmail\WebmailOnBehalfService;
use App\Support\Http\NoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * createWebmailSignInOnBehalf (feature 007 FR-054 to FR-056): a full browser session of an
 * administrator, or of a domain administrator with `loginAs`, opens a mailbox's webmail in the
 * requesting browser.
 */
class MailboxWebmailSignInController extends Controller
{
    public function store(Request $request, string $mailbox, WebmailOnBehalfService $webmail): JsonResponse
    {
        $handOff = $webmail->signIn(RequestContext::requirePrincipal($request), $mailbox, $request);

        return NoStore::apply(response()
            ->json(['data' => $handOff->toArray()], 200, [], JSON_UNESCAPED_SLASHES)
            ->withCookie($handOff->cookie));
    }
}
