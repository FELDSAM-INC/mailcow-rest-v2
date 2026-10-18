<?php

namespace App\Models\Mailcow;

/**
 * mailcow `spamalias`: temporary aliases. Read only; feature 003 writes them through
 * app/Services/Mailboxes.
 */
class SpamAlias extends MailcowModel
{
    protected $table = 'spamalias';

    protected $primaryKey = 'address';

    protected $keyType = 'string';

    public $incrementing = false;
}
