<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Rules\RoutingEmail;
use App\Services\Audit\RefusalAuditor;
use App\Services\Mailboxes\MailboxAddress;
use App\Support\Domain\DomainName;
use App\Support\Routing\NextHop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolvers of feature 009 for the shared refusal auditing (research R22): the entry of the
 * path (its numeric ID or, for a forwarding host, its stored address) with its stored name when it
 * exists; for a creation the value it would store; for an assignment the normalised domain name or
 * mailbox address, so that the generic `targetId` filter finds it by domain or mailbox (FR-086).
 * Objects are looked up regardless of the caller's scope, because the audit log is for
 * administrators. A refused bulk request is recorded once with a null target, the requested
 * `targetIds` and the action; its event follows the action.
 */
final class RoutingAuditTargets
{
    /** target type => [table, route parameter, name column, creation member, deletion event, update event] */
    private const ENTRIES = [
        'relayhost' => ['relayhosts', 'relayhostId', 'hostname', 'host', AuditEventType::RelayhostDeletion, AuditEventType::RelayhostUpdate],
        'transport' => ['transports', 'transportId', 'destination', 'destination', AuditEventType::TransportDeletion, AuditEventType::TransportUpdate],
        'bccMap' => ['bcc_maps', 'bccMapId', 'local_dest', 'localDestination', AuditEventType::BccMapDeletion, AuditEventType::BccMapUpdate],
        'recipientMap' => ['recipient_maps', 'recipientMapId', 'old_dest', 'originalDestination', AuditEventType::RecipientMapDeletion, AuditEventType::RecipientMapUpdate],
        'tlsPolicyMap' => ['tls_policy_override', 'tlsPolicyMapId', 'dest', 'destination', AuditEventType::TlsPolicyMapDeletion, AuditEventType::TlsPolicyMapUpdate],
    ];

    public static function register(RefusalAuditor $auditor): void
    {
        foreach (array_keys(self::ENTRIES) as $type) {
            $auditor->resolveTargetsWith($type, fn (Request $request, array $config): array => self::entry($type, $request, $config));
        }

        $auditor->resolveTargetsWith('forwardingHost', self::forwardingHost(...));
        $auditor->resolveTargetsWith('domainRelayhostAssignment', self::domainAssignment(...));
        $auditor->resolveTargetsWith('mailboxRelayhostAssignment', self::mailboxAssignment(...));
    }

    /**
     * The audit target of an entry ID as sent: with its stored name when it exists.
     *
     * @return array{type: string, id: string, name: string|null}
     */
    public static function entryTarget(string $type, string $id): array
    {
        [$table, , $nameColumn] = self::ENTRIES[$type];
        $numeric = ctype_digit($id) && strlen($id) <= 10;
        $name = $numeric ? DB::table($table)->where('id', (int) $id)->value($nameColumn) : null;

        return ['type' => $type, 'id' => mb_substr($numeric ? (string) (int) $id : $id, 0, 255), 'name' => is_string($name) ? mb_substr($name, 0, 255) : null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    private static function entry(string $type, Request $request, array $config): array
    {
        [, $parameter, , $member, $deletion, $update] = self::ENTRIES[$type];

        if (($config['event'] ?? null) === 'fromBulkAction') {
            return self::bulk($request, $deletion, $update);
        }

        $id = $request->route($parameter);

        if (is_string($id) && $id !== '') {
            return ['target' => self::entryTarget($type, $id)];
        }

        $raw = $request->input($member);

        if (! is_string($raw) || trim($raw) === '') {
            return ['target' => ['type' => $type, 'id' => null, 'name' => null]];
        }

        $name = $type === 'relayhost' ? (NextHop::tryParse($raw)?->stored() ?? trim($raw)) : trim($raw);

        return ['target' => RoutingAudit::target($type, null, $name)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    private static function forwardingHost(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return self::bulk($request, AuditEventType::ForwardingHostDeletion, AuditEventType::ForwardingHostUpdate);
        }

        $address = $request->route('forwardingHostAddress');

        if (is_string($address) && $address !== '') {
            return ['target' => RoutingAudit::target('forwardingHost', $address, $address)];
        }

        $host = $request->input('host');
        $source = is_string($host) ? trim($host) : '';

        return ['target' => RoutingAudit::target('forwardingHost', $source === '' ? null : $source, $source === '' ? null : $source)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null}
     */
    private static function domainAssignment(Request $request, array $config): array
    {
        $raw = $request->route('domain');

        if (! is_string($raw) || trim($raw) === '') {
            return ['target' => null];
        }

        $name = DomainName::normalize($raw) ?? mb_strtolower(trim($raw));

        return ['target' => RoutingAudit::target('domainRelayhostAssignment', $name, $name)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null}
     */
    private static function mailboxAssignment(Request $request, array $config): array
    {
        $raw = $request->route('mailbox');

        if (! is_string($raw) || trim($raw) === '') {
            return ['target' => null];
        }

        $address = MailboxAddress::normalize($raw)->address ?? RoutingEmail::normalize($raw) ?? mb_strtolower(trim($raw));

        return ['target' => RoutingAudit::target('mailboxRelayhostAssignment', $address, $address)];
    }

    /**
     * @return array{target: null, details: array<string, mixed>, event: AuditEventType}
     */
    private static function bulk(Request $request, AuditEventType $deletion, AuditEventType $update): array
    {
        return RefusalAuditor::bulkRefusal($request, $request->input('action') === 'delete' ? $deletion : $update);
    }
}
