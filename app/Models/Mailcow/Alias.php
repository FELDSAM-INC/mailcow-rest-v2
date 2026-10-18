<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `alias`. Feature 004 manages aliases; feature 002 counts and removes them.
 *
 * @property int $id
 * @property string $address
 * @property string $goto
 * @property string $domain
 * @property bool $active
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
}
