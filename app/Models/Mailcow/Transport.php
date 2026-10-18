<?php

namespace App\Models\Mailcow;

/**
 * mailcow `transports` (feature 009). Written only by App\Services\Routing\TransportService. The
 * password is hidden from serialisation and read explicitly only by the connection test.
 *
 * @property int $id
 * @property string $destination
 * @property string $nexthop
 * @property string $username
 * @property bool $is_mx_based
 * @property bool $active
 */
class Transport extends MailcowModel
{
    protected $table = 'transports';

    protected $primaryKey = 'id';

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['is_mx_based' => 'boolean', 'active' => 'boolean'];
    }
}
