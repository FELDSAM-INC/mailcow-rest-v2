<?php

namespace App\Models;

use App\Models\Mailcow\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An API key (`restv2_api_keys`, FR-032–FR-036, R19). Written only by
 * App\Services\ApiKeys\ApiKeyService (creation, revocation, feature 007's revocation of a deleted
 * owner's keys and the transfer on a rename) and ApiKeyAuthenticator (last use). Times are UTC,
 * except `owner_created_at`, which is the owner's raw mailcow `created` value, compared verbatim.
 *
 * @property string $id
 * @property string $name
 * @property string $prefix
 * @property string $secret_hash
 * @property string $owner_username
 * @property string $access_level `ro` or `rw`
 * @property list<string> $allowed_networks
 * @property CarbonImmutable|null $expires_at
 * @property string $created_by
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_used_from
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revocation_reason feature 007: `manual` or `ownerDeleted`, null while not revoked
 * @property string|null $owner_created_at feature 007: the owner's raw `admin.created` at creation (FR-036)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Admin|null $owner
 */
class ApiKey extends Model
{
    use HasUlids;

    public const READ_ONLY = 'ro';

    public const READ_WRITE = 'rw';

    protected $table = 'restv2_api_keys';

    protected $guarded = [];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'allowed_networks' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * ULIDs in their canonical upper-case form (contract `ApiKey.id`).
     */
    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * The owning administrator or domain administrator (mailcow `admin`), null once deleted.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_username', 'username');
    }

    /**
     * `revoked` once revoked, otherwise `expired` once the expiry has passed, otherwise `active`
     * (contract `ApiKeyStatus`).
     */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(CarbonImmutable::now()) => 'expired',
            default => 'active',
        };
    }

    /**
     * Contract `ApiKeyAccessLevel`.
     */
    public function accessLevel(): string
    {
        return $this->access_level === self::READ_ONLY ? 'readOnly' : 'readWrite';
    }

    public static function storedAccessLevel(string $accessLevel): string
    {
        return $accessLevel === 'readOnly' ? self::READ_ONLY : self::READ_WRITE;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn (Builder $q) => $q
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeRevoked(Builder $query): void
    {
        $query->whereNotNull('revoked_at');
    }
}
