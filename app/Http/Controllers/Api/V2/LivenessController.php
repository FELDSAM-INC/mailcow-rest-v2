<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * getLiveness: answers while the PHP runtime serves requests, without touching any dependency.
 */
class LivenessController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'ok']]);
    }
}
