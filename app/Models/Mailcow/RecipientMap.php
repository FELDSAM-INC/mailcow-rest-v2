<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `recipient_maps` (feature 009). Written only by App\Services\Routing\RecipientMapService.
 * Postfix's `recipient_canonical_maps` reads it per lookup.
 *
 * @property int $id
 * @property string $old_dest
 * @property string $new_dest
 * @property bool $active
 */
class RecipientMap extends MailcowModel
{
    protected $table = 'recipient_maps';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created' => MailcowDateTime::class, 'modified' => MailcowDateTime::class];
    }
}
