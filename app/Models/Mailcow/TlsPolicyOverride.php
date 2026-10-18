<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `tls_policy_override` (contract `TlsPolicyMap`, feature 009). Written only by
 * App\Services\Routing\TlsPolicyMapService. Postfix's `smtp_tls_policy_maps` reads it per lookup.
 *
 * @property int $id
 * @property string $dest
 * @property string $policy
 * @property string|null $parameters
 * @property bool $active
 */
class TlsPolicyOverride extends MailcowModel
{
    protected $table = 'tls_policy_override';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created' => MailcowDateTime::class, 'modified' => MailcowDateTime::class];
    }
}
