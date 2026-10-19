<?php

namespace App\Services\Quarantine;

use App\Audit\AuditEventType;
use App\Services\Audit\RefusalAuditor;
use App\Services\Domains\DomainAuditTargets;
use App\Services\Mailboxes\MailboxAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolvers of feature 006 for the shared refusal auditing (research R19, FR-063):
 *
 *  - `quarantineItem`: the path's item, with its queue identifier, recipient and envelope sender
 *    looked up regardless of the caller's scope (the audit log is for administrators); never the
 *    subject, the content or the quick-action hash;
 *  - `quarantineSettings`, `quarantineNotificationSettings` (the path's mailbox) and `spamScore`;
 *  - `spamPolicyEntry`: the entry's owner (domain, alias domain or mailbox, from the route's
 *    `ownerKind`), so that History lists every change of one owner's lists (G15), with the entry ID;
 *  - `rateLimit` (the object of the route's `kind`) and `rateLimitBucket`.
 *
 * A bulk request refused as a whole is recorded once with a null target, `targetIds` and
 * `bulkAction`; its event follows the action. Feature 006 maps `quarantine-delivery-refused` to the
 * failure reason `serviceFailure`; busy items and existing entries are `conflict` (status 409).
 */
final class SpamFilterAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('quarantineItem', self::item(...));
        $auditor->resolveTargetsWith('quarantineSettings', fn (): array => ['target' => ['type' => 'quarantineSettings', 'id' => null, 'name' => null]]);
        $auditor->resolveTargetsWith('quarantineNotificationSettings', self::notificationSettings(...));
        $auditor->resolveTargetsWith('spamScore', fn (Request $request): array => ['target' => self::mailboxTarget('spamScore', $request->route('mailbox'))]);
        $auditor->resolveTargetsWith('spamPolicyEntry', self::entry(...));
        $auditor->resolveTargetsWith('rateLimit', self::rateLimit(...));
        $auditor->resolveTargetsWith('rateLimitBucket', self::bucket(...));
        $auditor->mapProblemType('/api/v2/problems/quarantine-delivery-refused', 'serviceFailure');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function item(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return RefusalAuditor::bulkRefusal($request, match ($request->input('action')) {
                'learnAsSpam' => AuditEventType::QuarantineItemSpamLearning,
                'delete' => AuditEventType::QuarantineItemDeletion,
                default => AuditEventType::QuarantineItemRelease,
            });
        }

        $id = $request->route('itemId');

        if (! is_string($id) || ! ctype_digit($id)) {
            return ['target' => null];
        }

        $row = DB::table('quarantine')->where('id', (int) $id)->first(['qid', 'rcpt', 'sender']);
        $details = $row === null ? [] : ['queueId' => (string) $row->qid, 'recipient' => (string) $row->rcpt, 'sender' => (string) $row->sender];

        return ['target' => ['type' => 'quarantineItem', 'id' => $id, 'name' => $row === null ? null : (string) $row->qid], 'details' => $details];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function notificationSettings(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return RefusalAuditor::bulkRefusal($request, AuditEventType::QuarantineNotificationSettingsUpdate);
        }

        return ['target' => self::mailboxTarget('quarantineNotificationSettings', $request->route('mailbox'))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null, details: array<string, mixed>}
     */
    public static function entry(Request $request, array $config): array
    {
        $kind = (string) ($config['ownerKind'] ?? 'mailbox');
        $target = match ($kind) {
            'domain' => DomainAuditTargets::named('domain', $request->route('domain')),
            'aliasDomain' => DomainAuditTargets::named('aliasDomain', $request->route('aliasDomain')),
            default => self::mailboxTarget('mailbox', $request->route('mailbox')),
        };
        $details = [];
        $entryId = $request->route('entryId');

        if (is_string($entryId) && ctype_digit($entryId)) {
            $details['entryId'] = (int) $entryId;
        }

        $list = $request->input('list');

        if (in_array($list, ['allow', 'block'], true)) {
            $details['list'] = $list;
        }

        $pattern = $request->input('pattern');

        if (is_string($pattern)) {
            $details['pattern'] = mb_substr(mb_strtolower(trim($pattern)), 0, 255);
        }

        return ['target' => $target, 'details' => $details];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null, details: array<string, mixed>}
     */
    public static function rateLimit(Request $request, array $config): array
    {
        $kind = (string) ($config['kind'] ?? 'domain');
        $raw = $request->route(match ($kind) {
            'aliasDomain' => 'aliasDomain',
            'mailbox' => 'mailbox',
            default => 'domain',
        });
        $named = $kind === 'mailbox' ? self::mailboxTarget('rateLimit', $raw) : DomainAuditTargets::named('rateLimit', $raw);

        return ['target' => $named === null ? null : ['type' => 'rateLimit', 'id' => $named['id'], 'name' => $named['id']], 'details' => ['kind' => $kind]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null}
     */
    public static function bucket(Request $request, array $config): array
    {
        $id = $request->route('bucketId');

        return ['target' => is_string($id) && $id !== '' ? ['type' => 'rateLimitBucket', 'id' => mb_substr($id, 0, 255), 'name' => mb_substr($id, 0, 255)] : null];
    }

    /**
     * A mailbox target (`id` and `name` = the normalised address, or the value as sent cut to 255
     * characters when it is not an address).
     *
     * @return array{type: string, id: string, name: string}|null
     */
    public static function mailboxTarget(string $type, mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $address = MailboxAddress::normalize($raw)?->address ?? mb_substr($raw, 0, 255);

        return ['type' => $type, 'id' => $address, 'name' => $address];
    }
}
