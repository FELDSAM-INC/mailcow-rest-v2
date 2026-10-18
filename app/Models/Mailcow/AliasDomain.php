<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `alias_domain`: a domain whose addresses map to the same local parts of its target
 * domain. Feature 004 manages them; feature 002 removes them with their target domain.
 *
 * @property string $alias_domain
 * @property string $target_domain
 * @property bool $active
 */
class AliasDomain extends MailcowModel
{
    protected $table = 'alias_domain';

    protected $primaryKey = 'alias_domain';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
