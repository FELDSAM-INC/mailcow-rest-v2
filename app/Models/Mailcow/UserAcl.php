<?php

namespace App\Models\Mailcow;

/**
 * mailcow `user_acl`: a mailbox user's permission flags (TINYINT columns). Read only.
 *
 * @property string $username
 */
class UserAcl extends MailcowModel
{
    protected $table = 'user_acl';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;
}
