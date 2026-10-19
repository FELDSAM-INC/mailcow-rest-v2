<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;

/**
 * mailcow `settingsmap`: the rules of the Rspamd settings map (feature 013 FR-050). php-fpm's
 * `settings.php` emits the active rows to Rspamd. Written only by
 * App\Services\Rspamd\RspamdSettingsRuleService.
 *
 * @property int $id
 * @property string $desc
 * @property string $content
 * @property bool $active
 * @property CarbonImmutable|null $created
 * @property CarbonImmutable|null $modified
 */
class RspamdSettingsRule extends MailcowModel
{
    protected $table = 'settingsmap';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created' => MailcowDateTime::class,
            'modified' => MailcowDateTime::class,
        ];
    }
}
