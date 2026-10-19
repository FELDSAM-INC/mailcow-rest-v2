<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Services\RateLimits\RateLimitBucketService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * resetRateLimitBucket (feature 006 FR-057).
 */
class RateLimitBucketController extends Controller
{
    public function destroy(Request $request, string $bucketId, RateLimitBucketService $buckets): Response
    {
        $buckets->reset(RequestContext::requirePrincipal($request), $bucketId);

        return response()->noContent();
    }
}
