<?php

namespace App\Models\Mailcow;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * mailcow `mta_sts`: the MTA-STS policy of a domain (feature 010 FR-032 to FR-037), stored exactly
 * as legacy `mailbox('add'|'edit', 'mta_sts')` writes it and read by mailcow's `mta-sts.php` and
 * its certificate service: `version` lower-case `stsv1`, `mode` lower-case, `mx` the hosts joined by
 * `,`, `max_age` a decimal string, `active` 0 or 1, and `id` the 14-digit policy identifier. The
 * identifier column is not the key; it is exposed as `policy_id`. Written only by
 * App\Services\MtaSts\MtaStsPolicyService.
 *
 * @property string $domain
 * @property string $version
 * @property string $mode
 * @property string $mx
 * @property string $max_age
 * @property bool $active
 * @property-read string $policy_id
 */
class MtaStsPolicy extends MailcowModel
{
    protected $table = 'mta_sts';

    protected $primaryKey = 'domain';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * The policy identifier (column `id`), as its 14 digits.
     *
     * @return Attribute<string, never>
     */
    protected function policyId(): Attribute
    {
        return Attribute::get(fn (mixed $value, array $attributes): string => (string) ($attributes['id'] ?? ''));
    }
}
