<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `bcc_maps` (feature 009). Written only by App\Services\Routing\BccMapService. Rspamd's
 * bcc.php reads it per message (`type` `rcpt` or `sender`, `local_dest` an address or `@domain`).
 *
 * @property int $id
 * @property string $local_dest
 * @property string $bcc_dest
 * @property string $domain
 * @property string $type
 * @property bool $active
 */
class BccMap extends MailcowModel
{
    protected $table = 'bcc_maps';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created' => MailcowDateTime::class, 'modified' => MailcowDateTime::class];
    }
}
