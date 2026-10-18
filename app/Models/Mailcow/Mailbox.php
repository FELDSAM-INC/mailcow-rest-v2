<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `mailbox`: mailbox users and resources (`kind` location, thing or group). Read only in
 * feature 001.
 *
 * @property string $username
 * @property string $password
 * @property string $domain
 * @property string $kind
 * @property string|null $authsource
 * @property bool $active
 * @property array<string, mixed>|null $attributes
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
            'attributes' => 'array',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }

    public function isResource(): bool
    {
        return preg_match('/'.self::RESOURCE_KIND_PATTERN.'/', (string) $this->kind) === 1;
    }
}
