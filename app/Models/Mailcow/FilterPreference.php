<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;

/**
 * mailcow `filterconf`: the spam filter preferences of domains, alias domains and mailboxes, read
 * by Rspamd's settings map (feature 006 data-model): allow and block list entries
 * (`whitelist_from`, `blacklist_from`) and a mailbox's spam score (`lowspamlevel`,
 * `highspamlevel`). Written only by App\Services\SpamPolicy.
 *
 * @property int $prefid
 * @property string $object
 * @property string $option
 * @property string $value
 * @property CarbonImmutable|null $created
 */
class FilterPreference extends MailcowModel
{
    protected $table = 'filterconf';

    protected $primaryKey = 'prefid';

    protected function casts(): array
    {
        return ['created' => MailcowDateTime::class, 'modified' => MailcowDateTime::class];
    }
}
