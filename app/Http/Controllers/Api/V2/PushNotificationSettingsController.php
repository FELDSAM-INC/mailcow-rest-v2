<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ReplacePushNotificationSettingsRequest;
use App\Services\PushNotifications\PushNotificationService;
use App\Services\SelfService\MailboxAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * getPushNotificationSettings, replacePushNotificationSettings, deletePushNotificationSettings and
 * verifyPushNotificationSettings (feature 005 FR-054–FR-057).
 */
class PushNotificationSettingsController extends Controller
{
    public function __construct(
        private readonly PushNotificationService $pushover,
        private readonly MailboxAccess $access,
    ) {}

    public function show(Request $request, string $mailbox): JsonResponse
    {
        return $this->respond($this->pushover->read($this->access->resolve(RequestContext::requirePrincipal($request), $mailbox)));
    }

    public function update(ReplacePushNotificationSettingsRequest $request): JsonResponse
    {
        return $this->respond($this->pushover->replace($request, RequestContext::requirePrincipal($request), $request->mailbox(), $request->members()));
    }

    public function destroy(Request $request, string $mailbox): Response
    {
        $principal = RequestContext::requirePrincipal($request);
        $this->pushover->delete($request, $principal, $this->access->resolve($principal, $mailbox));

        return response()->noContent();
    }

    public function verify(Request $request, string $mailbox): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);

        return response()->json(['data' => $this->pushover->verify($principal, $this->access->resolve($principal, $mailbox))]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function respond(array $settings): JsonResponse
    {
        return response()->json(['data' => $settings], 200, ['ETag' => (string) $settings['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
