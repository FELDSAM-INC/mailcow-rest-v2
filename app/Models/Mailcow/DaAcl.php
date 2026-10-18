<?php

namespace App\Models\Mailcow;

/**
 * mailcow `da_acl`: a domain administrator's permission flags (TINYINT columns). Read only.
 *
 * @property string $username
 */
class DaAcl extends MailcowModel
{
    protected $table = 'da_acl';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;
}
