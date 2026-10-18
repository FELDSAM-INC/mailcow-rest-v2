<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `app_passwd`: alternative passwords of a mailbox for selected protocols (feature 005).
 * Read through this model; App\Services\AppPasswords\AppPasswordService writes the table. The hash
 * is hidden and never selected for responses.
 *
 * @property int $id
 * @property string $name
 * @property string $mailbox
 * @property string $domain
 * @property bool $active
 */
class AppPassword extends MailcowModel
{
    protected $table = 'app_passwd';

    protected $primaryKey = 'id';

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'active' => 'boolean',
            'imap_access' => 'boolean',
            'smtp_access' => 'boolean',
            'dav_access' => 'boolean',
            'eas_access' => 'boolean',
            'pop3_access' => 'boolean',
            'sieve_access' => 'boolean',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
