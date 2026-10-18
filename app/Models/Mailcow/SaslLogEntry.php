<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `sasl_log`: recent logins (primary key service, real_rip, username). Read only:
 * App\Services\Auth\RecentLoginRecorder writes it.
 *
 * @property string $service
 * @property string $real_rip
 * @property string $username
 * @property int|null $app_password
 */
class SaslLogEntry extends MailcowModel
{
    protected $table = 'sasl_log';

    protected $primaryKey = null;

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'app_password' => 'integer',
            'datetime' => MailcowDateTime::class,
        ];
    }
}
