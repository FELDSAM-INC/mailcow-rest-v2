<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\PublicAddressService;
use Illuminate\Http\JsonResponse;

/**
 * getPublicAddresses (feature 011 FR-021): the public addresses, with the IP check enabled.
 */
class PublicAddressesController extends Controller
{
    public function show(PublicAddressService $addresses): JsonResponse
    {
        return response()->json(['data' => $addresses->addresses()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
