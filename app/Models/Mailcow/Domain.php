<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `domain`. Read only in feature 001.
 *
 * @property string $domain
 * @property bool $active
 */
class Domain extends MailcowModel
{
    protected $table = 'domain';

    protected $primaryKey = 'domain';

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
