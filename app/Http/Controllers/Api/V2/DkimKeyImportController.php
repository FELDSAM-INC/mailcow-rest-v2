<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ImportDkimKeyRequest;
use App\Http\Resources\Api\V2\DkimKeyChangeResource;
use App\Services\Dkim\DkimKeyService;
use Illuminate\Http\JsonResponse;

/**
 * importDkimKey (feature 010 US5, FR-017 to FR-021): `201` with `Location` for a name without a key,
 * `200` after an overwrite, both with the key's `ETag`.
 */
class DkimKeyImportController extends Controller
{
    public function store(ImportDkimKeyRequest $request, string $dkimName, DkimKeyService $service): JsonResponse
    {
        return DkimKeyChangeResource::response($service->import(
            RequestContext::requirePrincipal($request),
            $dkimName,
            $request->privateKey(),
            $request->selector(),
            $request->overwrite(),
            $request->header('If-Match'),
        ));
    }
}
