<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\PublicConfigResource;
use App\Services\Branding\PublicConfigService;
use App\Support\Http\ETag;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * getPublicConfig (FR-042–FR-044, SC-008): no authentication, read from mailcow on every request.
 * A strong ETag of the representation lets clients revalidate (`304`); `no-cache, public` makes
 * every cache revalidate, so that a change saved in the legacy UI shows on the next request.
 */
class PublicConfigController extends Controller
{
    public function show(Request $request, PublicConfigService $config): Response
    {
        $data = (new PublicConfigResource($config->get()))->toArray($request);
        $etag = ETag::of(['publicConfig', $data]);

        $response = ETag::ifNoneMatchMatches($request, $etag)
            ? response('', 304)
            : response()->json(['data' => $data], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response->header('ETag', $etag)->header('Cache-Control', 'no-cache, public');
    }
}
