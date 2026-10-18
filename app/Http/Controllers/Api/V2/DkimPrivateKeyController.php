<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\DkimPrivateKeyResource;
use App\Services\Config\MailcowConfig;
use App\Services\Dkim\DkimKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * exportDkimPrivateKey (feature 010 US6, FR-026): a read (read-only keys of administrators may use
 * it) whose every attempt is audited: successes by the service, refusals through the route's
 * `restv2.audit` declaration.
 */
class DkimPrivateKeyController extends Controller
{
    public function show(Request $request, string $dkimName, DkimKeyService $service, MailcowConfig $config): JsonResponse
    {
        return DkimPrivateKeyResource::response($service->export(RequestContext::requirePrincipal($request), $dkimName, $config));
    }
}
