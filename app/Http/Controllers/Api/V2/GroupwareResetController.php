<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Groupware\GroupwareResetService;
use App\Services\SelfService\MailboxAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * resetActiveSyncCache and resetGroupwareProfile (feature 005 FR-064 to FR-066): the mailbox in the
 * caller's scope (`404` otherwise), then GroupwareResetService's checks.
 */
class GroupwareResetController extends Controller
{
    public function __construct(
        private readonly GroupwareResetService $resets,
        private readonly MailboxAccess $access,
    ) {}

    public function activeSyncCache(Request $request, string $mailbox): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->resets->resetActiveSyncCache($principal, $this->access->resolve($principal, $mailbox));

        return response()->noContent();
    }

    public function profile(Request $request, string $mailbox): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->resets->resetProfile($principal, $this->access->resolve($principal, $mailbox));

        return response()->noContent();
    }
}
