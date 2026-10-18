<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\CopyDkimKeyRequest;
use App\Http\Resources\Api\V2\DkimKeyChangeResource;
use App\Services\Dkim\DkimKeyService;
use Illuminate\Http\JsonResponse;

/**
 * copyDkimKey (feature 010 US5, FR-022, FR-023): the target's entry, `201` with `Location` when the
 * target had no key, `200` after an overwrite, both with the target key's `ETag`.
 */
class DkimKeyCopyController extends Controller
{
    public function store(CopyDkimKeyRequest $request, string $dkimName, DkimKeyService $service): JsonResponse
    {
        return DkimKeyChangeResource::response($service->copy(
            RequestContext::requirePrincipal($request),
            $dkimName,
            $request->target(),
            $request->overwrite(),
            $request->header('If-Match'),
        ));
    }
}
