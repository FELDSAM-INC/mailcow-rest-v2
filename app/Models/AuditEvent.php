<?php

namespace App\Models;

use App\Casts\JsonObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * One immutable audit record (`restv2_audit_events`, FR-046, FR-048). Written only by
 * App\Services\Audit\AuditLogger; updating or deleting a record throws. The retention purge
 * (`restv2:audit-purge`) deletes with the query builder.
 *
 * @property string $id
 * @property CarbonImmutable $occurred_at
 * @property string $event_type
 * @property string $outcome
 * @property string|null $actor_username
 * @property string|null $actor_role
 * @property string|null $actor_api_key_id
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $target_name
 * @property string $client_address
 * @property string|null $user_agent
 * @property string|null $auth_method
 * @property string|null $failure_reason
 * @property string $request_id
 * @property array<string, mixed> $details
 * @property string|null $impersonated_username feature 007: the impersonated account, or null
 * @property string|null $impersonated_role feature 007: `mailboxUser` or `domainAdmin`, or null
 */
class AuditEvent extends Model
{
    use HasUlids;

    protected $table = 'restv2_audit_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'details' => JsonObject::class,
        ];
    }

    /**
     * ULIDs in their canonical upper-case form (contract `AuditEvent.id`).
     */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit records are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit records are immutable.');
        });
    }
}
