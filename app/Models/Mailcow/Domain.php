<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use App\Casts\Mebibytes;
use Carbon\CarbonImmutable;

/**
 * mailcow `domain`. Written only by App\Services\Domains (feature 002). Quotas are cast to bytes.
 *
 * @property string $domain
 * @property string|null $description
 * @property int $aliases
 * @property int $mailboxes
 * @property int $defquota bytes
 * @property int $maxquota bytes
 * @property int $quota bytes
 * @property string $relayhost
 * @property bool $backupmx
 * @property bool $gal
 * @property bool $relay_all_recipients
 * @property bool $relay_unknown_only
 * @property CarbonImmutable|null $created
 * @property CarbonImmutable|null $modified
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
            'aliases' => 'integer',
            'mailboxes' => 'integer',
            'defquota' => Mebibytes::class,
            'maxquota' => Mebibytes::class,
            'quota' => Mebibytes::class,
            'backupmx' => 'boolean',
            'gal' => 'boolean',
            'relay_all_recipients' => 'boolean',
            'relay_unknown_only' => 'boolean',
            'active' => 'boolean',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
