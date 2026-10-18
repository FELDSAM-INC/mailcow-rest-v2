<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Webmail\WebmailHandOffService;
use App\Support\Http\ProblemType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * createWebmailHandOff (feature 005 FR-067, FR-068): a mailbox user's full session signs the
 * requesting browser in to webmail for the own mailbox.
 */
class WebmailHandOffController extends Controller
{
    public function store(Request $request, WebmailHandOffService $handOffs): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->role !== Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Only mailbox users open their webmail.');
        }

        $handOff = $handOffs->handOff($principal, $principal->username, $request);

        return response()
            ->json(['data' => $handOff->toArray()], 200, [], JSON_UNESCAPED_SLASHES)
            ->withCookie($handOff->cookie)
            ->header('Cache-Control', 'no-store');
    }
}
