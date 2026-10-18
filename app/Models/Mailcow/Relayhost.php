<?php

namespace App\Models\Mailcow;

/**
 * mailcow `relayhosts` (feature 009). Feature 002 reads the host name of a domain's relayhost; the
 * credentials are never read here.
 *
 * @property int $id
 * @property string $hostname
 * @property bool $active
 */
class Relayhost extends MailcowModel
{
    protected $table = 'relayhosts';

    protected $primaryKey = 'id';

    protected $hidden = ['username', 'password'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
