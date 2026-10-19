<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\UpdateQuarantineNotificationSettingsRequest;
use App\Http\Resources\Api\V2\QuarantineNotificationSettingsResource;
use App\Policies\QuarantinePolicy;
use App\Services\Access\SpamFilterScope;
use App\Services\Quarantine\QuarantineNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getMailboxQuarantineNotificationSettings and updateMailboxQuarantineNotificationSettings
 * (feature 006 FR-035, FR-036).
 */
class QuarantineNotificationSettingsController extends Controller
{
    public function show(Request $request, string $mailbox, QuarantineNotificationService $settings): JsonResponse
    {
        return QuarantineNotificationSettingsResource::response($settings->read(RequestContext::requirePrincipal($request), $mailbox));
    }

    public function update(UpdateQuarantineNotificationSettingsRequest $request, string $mailbox, QuarantinePolicy $policy, SpamFilterScope $scope, QuarantineNotificationService $settings): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $policy->authorize($principal, QuarantinePolicy::VIEW_NOTIFICATIONS);

        return QuarantineNotificationSettingsResource::response($settings->update($principal, $scope->mailbox($principal, $mailbox), $request->patch(), $request->header('If-Match')));
    }
}
