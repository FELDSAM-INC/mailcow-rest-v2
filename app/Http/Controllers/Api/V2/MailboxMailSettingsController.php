<?php

namespace App\Http\Controllers\Api\V2;

use App\Audit\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\MailboxMailSettingsBulkActionRequest;
use App\Http\Requests\Api\V2\UpdateMailboxMailSettingsRequest;
use App\Services\MailSettings\MailSettingsService;
use App\Services\SelfService\MailboxAccess;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * getMailboxMailSettings, updateMailboxMailSettings and applyMailboxMailSettingsBulkAction
 * (feature 005 FR-058–FR-060).
 */
class MailboxMailSettingsController extends Controller
{
    public function show(Request $request, string $mailbox, MailboxAccess $access, MailSettingsService $settings): JsonResponse
    {
        return $this->respond($settings->read($access->resolve(RequestContext::requirePrincipal($request), $mailbox)));
    }

    public function update(UpdateMailboxMailSettingsRequest $request, MailSettingsService $settings): JsonResponse
    {
        return $this->respond($settings->update(RequestContext::requirePrincipal($request), $request->mailbox(), $request->patch(), $request->header('If-Match')));
    }

    public function bulk(MailboxMailSettingsBulkActionRequest $request, MailboxAccess $access, MailSettingsService $settings, BulkActionRunner $runner): JsonResponse
    {
        $principal = RequestContext::requirePrincipal($request);
        $bulkRequestId = null;

        $result = $runner->run(
            $request,
            $principal,
            AuditEventType::MailSettingsUpdate,
            $request->targets(),
            fn (string $id): ?string => $access->find($principal, $id),
            function (string $mailbox, ?string $ifMatch) use ($request, $principal, $settings, &$bulkRequestId): string {
                $bulkRequestId ??= RequestContext::requestId($request);

                return $settings->update($principal, $mailbox, $request->patch(), $ifMatch, $bulkRequestId)['etag'];
            },
            fn (string $id, ?string $mailbox): array => ['type' => 'mailSettings', 'id' => $mailbox ?? mb_substr($id, 0, 255), 'name' => null],
        );

        return response()->json(['data' => $result], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function respond(array $settings): JsonResponse
    {
        return response()->json(['data' => $settings], 200, ['ETag' => (string) $settings['etag']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
