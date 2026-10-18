<?php

namespace App\Models\Mailcow;

/**
 * mailcow `relayhosts` (legacy "sender-dependent transports", feature 009). Written only by
 * App\Services\Routing\RelayhostService. The password never leaves the database through the API
 * (feature 009 FR-012): it is hidden from serialisation and read explicitly only by the connection
 * test.
 *
 * @property int $id
 * @property string $hostname
 * @property string $username
 * @property bool $active
 */
class Relayhost extends MailcowModel
{
    protected $table = 'relayhosts';

    protected $primaryKey = 'id';

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
