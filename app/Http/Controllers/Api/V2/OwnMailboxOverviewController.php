<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Account\MailboxOverviewService;
use App\Support\Http\ProblemType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getOwnMailboxOverview (feature 005 FR-069): the signed-in mailbox user's own mailbox at a glance.
 */
class OwnMailboxOverviewController extends Controller
{
    public function show(Request $request, MailboxOverviewService $overviews): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->role !== Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Only mailbox users have a mailbox overview.');
        }

        return response()->json(['data' => $overviews->overview($principal)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
