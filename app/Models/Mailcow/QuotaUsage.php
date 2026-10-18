<?php

namespace App\Models\Mailcow;

/**
 * mailcow `quota2`: the usage records of mailboxes, maintained by Dovecot. Read only; feature 003
 * writes them through app/Services/Mailboxes.
 */
class QuotaUsage extends MailcowModel
{
    protected $table = 'quota2';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;
}
