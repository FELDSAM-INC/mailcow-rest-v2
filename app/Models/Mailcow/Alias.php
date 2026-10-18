<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;

/**
 * mailcow `alias`. Feature 004 manages aliases; feature 002 counts and removes them.
 *
 * The row through which a mailbox receives mail at its own address (`address` equal to a
 * `mailbox.username`) belongs to the mailbox and is never an alias of feature 004
 * (scope notOwnAlias(), research R2). Derived values (data-model.md): `primaryDomain` (the target
 * domain when the alias lives in an alias domain), `inAliasDomain` and `orphaned` (its domain is
 * neither a domain nor an alias domain).
 *
 * @property int $id
 * @property string $address
 * @property string $goto
 * @property string $domain
 * @property bool $active
 * @property-read string $primary_domain
 * @property-read bool $in_alias_domain
 * @property-read bool $orphaned
 */
class Alias extends MailcowModel
{
    protected $table = 'alias';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sogo_visible' => 'boolean',
            'internal' => 'boolean',
            'sender_allowed' => 'boolean',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }

    /**
     * Leaves out the rows through which mailboxes receive mail at their own address.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotOwnAlias(Builder $query): void
    {
        $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('mailbox as own')->whereColumn('own.username', 'alias.address'));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function primaryDomain(): Attribute
    {
        return Attribute::get(fn (): string => (string) (DB::table('alias_domain')->where('alias_domain', $this->domain)->value('target_domain') ?? $this->domain));
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function inAliasDomain(): Attribute
    {
        return Attribute::get(fn (): bool => DB::table('alias_domain')->where('alias_domain', $this->domain)->exists());
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function orphaned(): Attribute
    {
        return Attribute::get(fn (): bool => ! DB::table('domain')->where('domain', $this->domain)->exists() && ! $this->in_alias_domain);
    }
}
