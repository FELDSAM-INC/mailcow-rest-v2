<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SecondFactorVerificationRequest;
use App\Http\Resources\Api\V2\SessionResource;
use App\Services\Auth\SecondFactorService;

/**
 * verifySecondFactor (FR-020–FR-025): completes the pending sign-in.
 */
class SecondFactorController extends Controller
{
    public function store(SecondFactorVerificationRequest $request, SecondFactorService $secondFactors): SessionResource
    {
        $secondFactors->verify($request, $request->validated());

        return SessionResource::fromSession($request->session());
    }
}
