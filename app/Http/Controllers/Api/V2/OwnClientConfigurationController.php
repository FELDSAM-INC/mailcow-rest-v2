<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\Account\ClientConfigurationService;
use App\Support\Http\ProblemType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getOwnClientConfiguration (feature 005 FR-083): how to set up a mail client for the signed-in
 * mailbox. Mailbox users only.
 */
class OwnClientConfigurationController extends Controller
{
    public function show(Request $request, ClientConfigurationService $configurations): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->role !== Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Only mailbox users have a client configuration.');
        }

        return response()->json(['data' => $configurations->forMailbox($principal->username, $request)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
