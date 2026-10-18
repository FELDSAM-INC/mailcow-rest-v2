<?php

namespace App\Models\Mailcow;

/**
 * mailcow `pushover`: push notification settings of mailboxes. Read only; feature 003 writes them
 * through app/Services/Mailboxes.
 */
class Pushover extends MailcowModel
{
    protected $table = 'pushover';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;
}
