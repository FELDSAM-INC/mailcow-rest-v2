<?php

namespace App\Models\Mailcow;

/**
 * mailcow `tags_domain`: one tag of a domain (no primary key; unique `(tag_name, domain)`, case
 * insensitive). Written only by App\Services\Domains.
 *
 * @property string $tag_name
 * @property string $domain
 */
class DomainTag extends MailcowModel
{
    protected $table = 'tags_domain';

    protected $primaryKey = null;

    public $incrementing = false;
}
