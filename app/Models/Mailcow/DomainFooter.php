<?php

namespace App\Models\Mailcow;

/**
 * mailcow `domain_wide_footer`, read by mailcow's mail filter (footer.php). Written only by
 * App\Services\Domains.
 *
 * @property string $domain
 * @property string|null $html
 * @property string|null $plain
 * @property list<string> $mbox_exclude
 * @property list<string> $alias_domain_exclude
 * @property bool $skip_replies
 */
class DomainFooter extends MailcowModel
{
    protected $table = 'domain_wide_footer';

    protected $primaryKey = 'domain';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'mbox_exclude' => 'array',
            'alias_domain_exclude' => 'array',
            'skip_replies' => 'boolean',
        ];
    }
}
