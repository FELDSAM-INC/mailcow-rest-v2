<?php

namespace App\Services\SyncJobs;

use App\Audit\AuditEventType;
use App\Auth\Role;
use App\Http\RequestContext;
use App\Services\Audit\RefusalAuditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The target resolver of feature 008 for the shared refusal auditing (research R16): `syncJob`.
 * A path job is named by its identifier and target mailbox, looked up regardless of the caller's
 * scope (the audit log is for administrators); a creation by the `mailbox` member as sent (cut to
 * 255 characters) or the mailbox user's own address; a bulk request refused as a whole by its
 * requested identifiers, with the event of its action.
 */
final class SyncJobAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('syncJob', self::resolve(...));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function resolve(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            $action = $request->input('action');

            return RefusalAuditor::bulkRefusal($request, (is_string($action) ? SyncJobBulkAction::tryFrom($action)?->auditEvent() : null) ?? AuditEventType::SyncJobUpdate);
        }

        $id = $request->route('syncJobId');

        if (is_string($id) && $id !== '') {
            $target = self::target($id);

            return ['target' => $target, 'details' => $target['name'] === null ? [] : ['mailbox' => $target['name']]];
        }

        $mailbox = $request->input('mailbox');
        $principal = RequestContext::principal($request);

        if (! is_string($mailbox) || trim($mailbox) === '') {
            $mailbox = $principal?->role === Role::MailboxUser ? $principal->username : null;
        }

        if ($mailbox === null) {
            return ['target' => ['type' => 'syncJob', 'id' => null, 'name' => null]];
        }

        $mailbox = mb_substr(trim($mailbox), 0, 255);

        return ['target' => ['type' => 'syncJob', 'id' => null, 'name' => $mailbox], 'details' => ['mailbox' => $mailbox]];
    }

    /**
     * The audit target of a job identifier as sent: with its target mailbox when it exists.
     *
     * @return array{type: string, id: string, name: string|null}
     */
    public static function target(string $id): array
    {
        $numeric = ctype_digit($id) && strlen($id) <= 10;
        $mailbox = $numeric ? DB::table('imapsync')->where('id', (int) $id)->value('user2') : null;

        return ['type' => 'syncJob', 'id' => mb_substr($numeric ? (string) (int) $id : $id, 0, 255), 'name' => is_string($mailbox) ? $mailbox : null];
    }
}
