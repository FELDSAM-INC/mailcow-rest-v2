<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\Settings\UpdateQuotaNotificationRecipientsRequest;
use App\Http\Resources\Api\V2\SettingsResource;
use App\Policies\QuotaNotificationRecipientsPolicy;
use App\Services\Settings\QuotaNotificationRecipientsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getQuotaNotificationRecipients and updateQuotaNotificationRecipients (feature 013 FR-046 to
 * FR-049): administrators for every domain, domain administrators for their domains.
 */
class QuotaNotificationRecipientsController extends Controller
{
    public function show(Request $request, string $domain, QuotaNotificationRecipientsPolicy $policy, QuotaNotificationRecipientsService $recipients): JsonResponse
    {
        return SettingsResource::response($recipients->read($policy->resolve(RequestContext::requirePrincipal($request), $domain)));
    }

    public function update(UpdateQuotaNotificationRecipientsRequest $request, QuotaNotificationRecipientsService $recipients): JsonResponse
    {
        return SettingsResource::response($recipients->update($request->domain(), $request->values(), $request->header('If-Match'), RequestContext::requirePrincipal($request)));
    }
}
