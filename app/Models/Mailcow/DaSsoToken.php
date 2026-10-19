<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;

/**
 * mailcow `da_sso`: single sign-on tokens of domain administrators (feature 007 FR-058), shared
 * with legacy's domain administrator login and `/api/v1`. Read only: App\Services\Auth\SsoTokenService
 * writes through the query builder. The primary key is (`token`, `created`).
 *
 * @property string $username
 * @property string $token
 */
class DaSsoToken extends MailcowModel
{
    protected $table = 'da_sso';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['created' => MailcowDateTime::class];
    }
}
