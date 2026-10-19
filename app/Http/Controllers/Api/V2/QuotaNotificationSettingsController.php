<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateQuotaNotificationSettingsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\QuotaNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getQuotaNotificationSettings and updateQuotaNotificationSettings (feature 013 FR-043 to FR-045).
 * Administrators only.
 */
class QuotaNotificationSettingsController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, QuotaNotificationService $notifications): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($notifications->read());
    }

    public function update(UpdateQuotaNotificationSettingsRequest $request, QuotaNotificationService $notifications): JsonResponse
    {
        /** @var array<string, string> $patch */
        $patch = $request->patch();

        return SettingsResource::response($notifications->update($patch, $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
