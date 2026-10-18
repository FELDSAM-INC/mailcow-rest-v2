<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A groupware resource of feature 004: a mailcow `mailbox` row whose kind is `location`, `thing` or
 * `group` (legacy `kind REGEXP 'location|thing|group'`), with the password `RESOURCE` and no quota.
 * Written only through App\Services\Resources.
 *
 * Booking rule (`multiple_bookings`): 0 `alwaysFree`, below 0 `unlimited` (written as -1), N above 0
 * `limited` with N concurrent bookings.
 *
 * @property string $username
 * @property string $name
 * @property string $kind
 * @property int $multiple_bookings
 * @property bool $active
 * @property-read string $booking_rule_value
 * @property-read int|null $booking_limit_value
 */
class Resource extends MailcowModel
{
    public const KINDS = ['location', 'thing', 'group'];

    protected $table = 'mailbox';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::addGlobalScope('resource', fn (Builder $query) => $query->where('kind', 'regexp', 'location|thing|group'));
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'multiple_bookings' => 'integer',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }

    public static function bookingRule(int $stored): string
    {
        return match (true) {
            $stored === 0 => 'alwaysFree',
            $stored < 0 => 'unlimited',
            default => 'limited',
        };
    }

    public static function bookingLimit(int $stored): ?int
    {
        return $stored > 0 ? $stored : null;
    }

    /**
     * @return Attribute<string, never>
     */
    protected function bookingRuleValue(): Attribute
    {
        return Attribute::get(fn (): string => self::bookingRule((int) $this->multiple_bookings));
    }

    /**
     * @return Attribute<int|null, never>
     */
    protected function bookingLimitValue(): Attribute
    {
        return Attribute::get(fn (): ?int => self::bookingLimit((int) $this->multiple_bookings));
    }
}
