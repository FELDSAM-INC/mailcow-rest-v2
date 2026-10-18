<?php

namespace App\Models\Mailcow;

/**
 * mailcow `identity_provider`: key/value settings of the external identity provider. Read only.
 *
 * @property string $key
 * @property string $value
 */
class IdentityProviderSetting extends MailcowModel
{
    protected $table = 'identity_provider';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;
}
