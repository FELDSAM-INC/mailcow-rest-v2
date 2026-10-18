<?php

namespace App\Services\Audit;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\RequestContext;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes audit records (FR-045, FR-046, R22). Call it inside the database transaction of the
 * audited change: a failed insert then rolls the change back. Refusals are written in their own
 * transaction before the error response. The request supplies the request ID, the client address
 * (REMOTE_ADDR, R23) and the user agent (truncated to 512 characters).
 */
class AuditLogger
{
    /**
     * @param  Principal|array{username?: string|null, role?: Role|string|null, apiKeyId?: string|null}|null  $actor
     * @param  array{type: string, id?: string|null, name?: string|null}|null  $target
     * @param  array<string, mixed>  $details  filtered by the event's detail keys
     */
    public function record(
        AuditEventType $type,
        bool $success,
        Principal|array|null $actor,
        ?array $target,
        ?string $authMethod,
        ?string $failureReason,
        array $details,
    ): AuditEvent {
        /** @var Request $request */
        $request = app('request');

        [$username, $role, $apiKeyId] = $this->actor($actor);
        $userAgent = $request->userAgent();

        $event = new AuditEvent;
        $event->forceFill([
            'occurred_at' => CarbonImmutable::now('UTC'),
            'event_type' => $type->value,
            'outcome' => $success ? 'success' : 'failure',
            'actor_username' => $username,
            'actor_role' => $role,
            'actor_api_key_id' => $apiKeyId,
            'target_type' => $target['type'] ?? null,
            'target_id' => $target['id'] ?? null,
            'target_name' => $target['name'] ?? null,
            'client_address' => self::canonicalAddress((string) $request->ip()),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'auth_method' => $authMethod,
            'failure_reason' => $failureReason,
            'request_id' => RequestContext::requestId($request),
            'details' => array_intersect_key($details, array_flip($type->detailKeys())),
        ])->save();

        return $event;
    }

    /**
     * The address in its canonical text form (lower-case, compressed IPv6), so that equal
     * addresses compare equal in the audit filters.
     */
    private static function canonicalAddress(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? $ip : (string) inet_ntop($packed);
    }

    /**
     * Follow-up outcomes that a record may complete after its change was committed (feature 002
     * research D16, plan Complexity Tracking).
     */
    private const FOLLOW_UP_KEYS = ['creationReport', 'maildirCleanup'];

    /**
     * Completes a follow-up outcome that the record was written with as `"pending"`: the creation
     * report of a domain creation, the stored-mail outcome of a domain deletion. Only these keys can
     * be completed, only once, and only from `"pending"`; everything else throws. This is the only
     * code that updates an audit record (records stay immutable through the model).
     *
     * @param  array<string, mixed>|string  $outcome
     */
    public function completeFollowUps(string $eventId, string $key, array|string $outcome): void
    {
        if (! in_array($key, self::FOLLOW_UP_KEYS, true)) {
            throw new LogicException("The audit detail {$key} cannot be completed.");
        }

        $updated = DB::table('restv2_audit_events')
            ->where('id', $eventId)
            ->whereRaw('JSON_VALUE(`details`, ?) = ?', ['$.'.$key, 'pending'])
            ->update(['details' => DB::raw('JSON_SET(`details`, '.DB::getPdo()->quote('$.'.$key).', JSON_EXTRACT('.DB::getPdo()->quote(json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).", '$'))")]);

        if ($updated !== 1) {
            throw new LogicException("The audit detail {$key} of record {$eventId} is not pending.");
        }
    }

    /**
     * @param  Principal|array<string, mixed>|null  $actor
     * @return array{string|null, string|null, string|null}
     */
    private function actor(Principal|array|null $actor): array
    {
        if ($actor instanceof Principal) {
            return [$actor->username, $actor->role->value, $actor->apiKey?->id];
        }

        $role = $actor['role'] ?? null;

        return [
            isset($actor['username']) ? (string) $actor['username'] : null,
            $role instanceof Role ? $role->value : (is_string($role) ? $role : null),
            isset($actor['apiKeyId']) ? (string) $actor['apiKeyId'] : null,
        ];
    }
}
