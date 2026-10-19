<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdatePasswordResetNotificationRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\SettingsPolicy;
use App\Services\Settings\PasswordResetNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getPasswordResetNotification and updatePasswordResetNotification (feature 013 FR-039 to FR-042).
 */
class PasswordResetNotificationController extends Controller
{
    public function show(Request $request, SettingsPolicy $policy, PasswordResetNotificationService $notifications): JsonResponse
    {
        $policy->requireAdmin(RequestContext::requirePrincipal($request));

        return SettingsResource::response($notifications->read());
    }

    public function update(UpdatePasswordResetNotificationRequest $request, PasswordResetNotificationService $notifications): JsonResponse
    {
        /** @var array<string, string> $patch */
        $patch = $request->patch();

        return SettingsResource::response($notifications->update($patch, $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
