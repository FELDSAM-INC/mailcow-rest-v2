<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `domain_admins`: a domain administrator's managed domains. Read only.
 *
 * @property int $id
 * @property string $username
 * @property string $domain
 * @property bool $active
 */
class DomainAdminAssignment extends MailcowModel
{
    protected $table = 'domain_admins';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created' => MailcowDateTime::class,
        ];
    }
}
