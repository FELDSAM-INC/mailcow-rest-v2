<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Services\Audit\RefusalAuditor;
use App\Support\Http\FieldViolations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolvers of feature 003 for the shared refusal auditing (research R22): `mailbox` (the
 * path address or the created address, normalised when possible, otherwise as sent, cut to 255
 * characters) and `mailboxTemplate` (the path ID or the created name). The audit log is for
 * administrators, so addresses outside the caller's scope may be recorded; the display name is
 * looked up without scope. A refused bulk request is recorded once with a null target, the
 * requested `targetIds` and the action; its event follows the action.
 */
final class MailboxAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('mailbox', self::mailbox(...));
        $auditor->resolveTargetsWith('mailboxTemplate', self::template(...));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function mailbox(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return RefusalAuditor::bulkRefusal($request, $request->input('action') === 'delete' ? AuditEventType::MailboxDeletion : AuditEventType::MailboxUpdate);
        }

        $raw = $request->route('mailbox');

        if (is_string($raw) && $raw !== '') {
            return ['target' => self::target($raw)];
        }

        $local = $request->input('localPart');
        $domain = $request->input('domain');

        if (! is_string($local) || ! is_string($domain)) {
            return ['target' => null];
        }

        $address = MailboxAddress::fromParts($local, $domain, new FieldViolations);

        return ['target' => [
            'type' => 'mailbox',
            'id' => mb_substr($address === null ? trim($local).'@'.trim($domain) : $address->address, 0, 255),
            'name' => null,
        ]];
    }

    /**
     * The audit target of a mailbox address as sent: normalised when it can be, with the display
     * name of an existing mailbox.
     *
     * @return array{type: string, id: string, name: string|null}
     */
    public static function target(string $raw): array
    {
        $address = MailboxAddress::normalize($raw)->address ?? mb_substr(trim($raw), 0, 255);
        $name = DB::table('mailbox')->where('username', $address)->value('name');

        return ['type' => 'mailbox', 'id' => mb_substr($address, 0, 255), 'name' => is_string($name) ? mb_substr($name, 0, 255) : null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function template(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return RefusalAuditor::bulkRefusal($request, AuditEventType::MailboxTemplateDeletion);
        }

        $id = $request->route('mailboxTemplateId');

        if (is_string($id) && $id !== '') {
            $name = ctype_digit($id) ? DB::table('templates')->where('type', 'mailbox')->where('id', (int) $id)->value('template') : null;

            return ['target' => ['type' => 'mailboxTemplate', 'id' => mb_substr($id, 0, 255), 'name' => is_string($name) ? $name : null]];
        }

        $name = $request->input('name');

        return ['target' => is_string($name) && trim($name) !== '' ? ['type' => 'mailboxTemplate', 'id' => null, 'name' => mb_substr(trim($name), 0, 255)] : null];
    }
}
