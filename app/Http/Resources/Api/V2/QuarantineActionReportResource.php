<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Quarantine\ActionReport;
use Illuminate\Http\JsonResponse;

/**
 * Contract `QuarantineActionReportResponse` (feature 006 FR-025): the outcome of each step of a
 * release or a learning as spam.
 */
final class QuarantineActionReportResource
{
    public static function response(ActionReport $report): JsonResponse
    {
        return response()->json(['data' => $report->toArray()], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
