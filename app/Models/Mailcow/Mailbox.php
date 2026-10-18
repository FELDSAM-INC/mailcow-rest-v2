<?php

namespace App\Models\Mailcow;

use App\Auth\Principal;
use App\Auth\Role;
use App\Casts\MailcowDateTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * mailcow `mailbox`: mailbox users and resources (`kind` location, thing or group). Read only;
 * feature 003 writes mailboxes through app/Services/Mailboxes.
 *
 * @property string $username
 * @property string $password
 * @property string|null $name
 * @property string|null $description
 * @property string|null $mailbox_path_prefix
 * @property int $quota
 * @property string $local_part
 * @property string $domain
 * @property array<string, mixed>|null $attributes
 * @property array<string, mixed>|null $custom_attributes
 * @property string $kind
 * @property int $multiple_bookings
 * @property string|null $authsource
 * @property bool $active
 */
class Mailbox extends MailcowModel
{
    /** Resource mailboxes never sign in (legacy `kind NOT REGEXP 'location|thing|group'`). */
    public const RESOURCE_KIND_PATTERN = 'location|thing|group';

    protected $table = 'mailbox';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'quota' => 'integer',
            'attributes' => 'array',
            'custom_attributes' => 'array',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }

    public function isResource(): bool
    {
        return preg_match('/'.self::RESOURCE_KIND_PATTERN.'/', (string) $this->kind) === 1;
    }

    /**
     * Mailboxes only, never resources (feature 003 FR-003).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMailboxesOnly(Builder $query): Builder
    {
        return $query->where('kind', '');
    }

    /**
     * Mailboxes the caller may reach: every one for an administrator, those of the actively
     * assigned domains for a domain administrator, none for anybody else (feature 003 FR-001).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInScope(Builder $query, Principal $principal): Builder
    {
        return match ($principal->role) {
            Role::Admin => $query,
            Role::DomainAdmin => $query->whereIn('domain', fn ($sub) => $sub->select('domain')->from('domain_admins')
                ->where('active', 1)
                ->where('username', $principal->username)),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
