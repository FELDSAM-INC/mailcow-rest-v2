<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Resources\Api\V2\DkimKeyChangeResource;
use App\Services\Dkim\DkimKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * publishDkimDnsRecord (feature 010 US7, FR-031): `200` with the entry, the publication outcome and
 * the key's `ETag`.
 */
class DkimDnsPublicationController extends Controller
{
    public function store(Request $request, string $dkimName, DkimKeyService $service): JsonResponse
    {
        return DkimKeyChangeResource::response($service->publishAgain(RequestContext::requirePrincipal($request), $dkimName, $request->header('If-Match')));
    }
}
