<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\DnsCapabilitiesResource;
use App\Services\Config\MailcowConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * getDnsCapabilities (feature 010 FR-012): administrators and domain administrators, read-only keys
 * included; mailbox users are forbidden.
 */
class DnsCapabilitiesController extends Controller
{
    public function show(Request $request, MailcowConfig $config): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        Gate::forUser($principal)->authorize('dnsDiagnostics.run');

        return response()->json(['data' => DnsCapabilitiesResource::present($principal, $config)], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
