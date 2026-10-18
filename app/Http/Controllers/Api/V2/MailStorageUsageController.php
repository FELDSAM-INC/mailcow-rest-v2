<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MailStorageService;
use Illuminate\Http\JsonResponse;

/**
 * getMailStorageUsage (feature 011 FR-007): the usage of the disk holding the mail storage.
 */
class MailStorageUsageController extends Controller
{
    public function show(MailStorageService $storage): JsonResponse
    {
        return response()->json(['data' => $storage->usage()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
