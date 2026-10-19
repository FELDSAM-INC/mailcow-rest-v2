<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\UpdateQuarantineSettingsRequest;
use App\Http\Resources\Api\V2\QuarantineSettingsResource;
use App\Policies\QuarantinePolicy;
use App\Services\Quarantine\QuarantineSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getQuarantineSettings and updateQuarantineSettings (feature 006 FR-029 to FR-034, FR-070).
 */
class QuarantineSettingsController extends Controller
{
    public function show(Request $request, QuarantinePolicy $policy, QuarantineSettingsService $settings): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, QuarantinePolicy::VIEW_SETTINGS);

        return QuarantineSettingsResource::response($principal->role === Role::Admin ? $settings->read() : $settings->readPublic());
    }

    public function update(UpdateQuarantineSettingsRequest $request, QuarantineSettingsService $settings): JsonResponse
    {
        $updated = $settings->update(RequestContext::requirePrincipal($request), $request->patch(), $request->header('If-Match'), $request->validateOnly());

        if ($updated === null) {
            return response()->json(['data' => ['valid' => true]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return QuarantineSettingsResource::response($updated);
    }
}
