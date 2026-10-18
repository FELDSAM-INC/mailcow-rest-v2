<?php

namespace App\Services\Aliases;

use App\Audit\AuditEventType;
use App\Services\Audit\RefusalAuditor;
use App\Services\Domains\DomainAuditTargets;
use App\Services\Mailboxes\MailboxAddress;
use App\Services\Resources\ResourceAddressDeriver;
use App\Support\Domain\DomainName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolvers of feature 004 for the shared refusal auditing (research R20): `alias` (the path
 * identifier, or the requested address of a creation), `aliasDomain` (the path name or the
 * requested name), `resource` (the path address, or the address a creation would derive) and
 * `mailboxSenderSettings` (the path mailbox). Objects are looked up regardless of the caller's
 * scope, because the audit log is for administrators; an object that does not exist is recorded
 * with the identifier as sent, cut to 255 characters. A refused bulk request is recorded once with
 * a null target, the requested `targetIds` and the action; its event follows the action.
 */
final class AliasAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('alias', self::alias(...));
        $auditor->resolveTargetsWith('aliasDomain', self::aliasDomain(...));
        $auditor->resolveTargetsWith('resource', self::resource(...));
        $auditor->resolveTargetsWith('mailboxSenderSettings', self::senderSettings(...));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function alias(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return self::bulk($request, [
                'delete' => AuditEventType::AliasDeletion,
                'copyOverAliasDomains' => AuditEventType::AliasCreation,
            ], AuditEventType::AliasUpdate);
        }

        $id = $request->route('aliasId');

        if (is_string($id) && $id !== '') {
            return ['target' => self::aliasTarget($id)];
        }

        $raw = $request->input('address');

        if (! is_string($raw) || trim($raw) === '') {
            return ['target' => null];
        }

        $address = AliasAddress::parse($raw)?->address ?? trim($raw);

        return ['target' => ['type' => 'alias', 'id' => null, 'name' => mb_substr($address, 0, 255)]];
    }

    /**
     * The audit target of an alias identifier as sent: with the stored address when it exists.
     *
     * @return array{type: string, id: string, name: string|null}
     */
    public static function aliasTarget(string $id): array
    {
        $address = ctype_digit($id) && strlen($id) <= 10 ? DB::table('alias')->where('id', (int) $id)->value('address') : null;

        return ['type' => 'alias', 'id' => mb_substr(ctype_digit($id) ? (string) (int) $id : $id, 0, 255), 'name' => is_string($address) ? $address : null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function aliasDomain(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return self::bulk($request, ['delete' => AuditEventType::AliasDomainDeletion], AuditEventType::AliasDomainUpdate);
        }

        return ['target' => DomainAuditTargets::named('aliasDomain', $request->route('aliasDomain') ?? $request->input('name'))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function resource(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return self::bulk($request, ['delete' => AuditEventType::ResourceDeletion], AuditEventType::ResourceUpdate);
        }

        $raw = $request->route('resourceAddress');

        if (is_string($raw) && $raw !== '') {
            return ['target' => self::resourceTarget($raw)];
        }

        $description = $request->input('description');
        $domain = $request->input('domain');

        if (! is_string($description)) {
            return ['target' => null];
        }

        $local = ResourceAddressDeriver::localPart(trim($description));
        $ascii = is_string($domain) ? DomainName::normalize($domain) : null;

        return ['target' => [
            'type' => 'resource',
            'id' => $local !== '' && $ascii !== null ? mb_substr($local.'@'.$ascii, 0, 255) : null,
            'name' => mb_substr(trim($description), 0, 255),
        ]];
    }

    /**
     * The audit target of a resource address as sent: the stored address and description when it
     * exists, otherwise the normalised address.
     *
     * @return array{type: string, id: string, name: string|null}
     */
    public static function resourceTarget(string $raw): array
    {
        $address = AliasAddress::parse($raw)?->address ?? trim($raw);
        $row = DB::table('mailbox')->where('username', $address)->where('kind', 'regexp', 'location|thing|group')->first(['username', 'name']);

        return $row === null
            ? ['type' => 'resource', 'id' => mb_substr($address, 0, 255), 'name' => null]
            : ['type' => 'resource', 'id' => (string) $row->username, 'name' => mb_substr((string) $row->name, 0, 255)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null}
     */
    public static function senderSettings(Request $request, array $config): array
    {
        $raw = $request->route('mailbox');

        if (! is_string($raw) || trim($raw) === '') {
            return ['target' => null];
        }

        $address = mb_substr(MailboxAddress::normalize($raw)?->address ?? trim($raw), 0, 255);

        return ['target' => ['type' => 'mailboxSenderSettings', 'id' => $address, 'name' => $address]];
    }

    /**
     * @param  array<string, AuditEventType>  $events  action => event
     * @return array{target: null, details: array<string, mixed>, event: AuditEventType}
     */
    private static function bulk(Request $request, array $events, AuditEventType $default): array
    {
        $action = $request->input('action');
        $targets = $request->input('targets');
        $ids = [];

        foreach (is_array($targets) ? array_slice($targets, 0, 100) : [] as $target) {
            if (is_array($target) && is_string($target['id'] ?? null)) {
                $ids[] = mb_substr($target['id'], 0, 255);
            }
        }

        return [
            'event' => is_string($action) && isset($events[$action]) ? $events[$action] : $default,
            'target' => null,
            'details' => ['targetIds' => $ids, 'bulkAction' => is_string($action) ? mb_substr($action, 0, 32) : null],
        ];
    }
}
