<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Services\Health\ReadinessChecker;
use App\Support\Http\ProblemType;
use Illuminate\Http\JsonResponse;

/**
 * getReadiness (R24): `200` with every check, or `503 service-unavailable` with the failed ones.
 */
class ReadinessController extends Controller
{
    public function show(ReadinessChecker $checker): JsonResponse
    {
        $checks = $checker->check();

        if (! ReadinessChecker::ready($checks)) {
            throw new ProblemException(ProblemType::ServiceUnavailable, null, ['checks' => $checks]);
        }

        return response()->json(['data' => ['status' => 'ok', 'checks' => $checks]]);
    }
}
