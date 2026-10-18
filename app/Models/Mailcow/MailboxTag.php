<?php

namespace App\Models\Mailcow;

/**
 * mailcow `tags_mailbox`: the tags of mailboxes, one row per tag and no primary key. Read only;
 * feature 003 writes them through app/Services/Mailboxes.
 */
class MailboxTag extends MailcowModel
{
    protected $table = 'tags_mailbox';

    protected $primaryKey = null;

    public $incrementing = false;
}
