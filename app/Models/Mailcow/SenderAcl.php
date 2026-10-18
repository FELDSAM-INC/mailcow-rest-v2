<?php

namespace App\Models\Mailcow;

/**
 * mailcow `sender_acl`: sender permissions. Read only; feature 003 writes them through
 * app/Services/Mailboxes.
 */
class SenderAcl extends MailcowModel
{
    protected $table = 'sender_acl';

    protected $primaryKey = 'id';
}
