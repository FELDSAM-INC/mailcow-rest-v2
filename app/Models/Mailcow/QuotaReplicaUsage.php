<?php

namespace App\Models\Mailcow;

/**
 * mailcow `quota2replica`: the replicated usage records of mailboxes. Read only; feature 003
 * writes them through app/Services/Mailboxes.
 */
class QuotaReplicaUsage extends MailcowModel
{
    protected $table = 'quota2replica';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;
}
