<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;

/**
 * mailcow `oauth_authorization_codes`, issued by the legacy `/oauth/*` endpoints (feature 012). Read only;
 * App\Services\Oauth2\Oauth2ClientService counts and removes them per client. `expires` is a
 * TIMESTAMP in mailcow's time zone.
 *
 * @property string $authorization_code
 * @property string $client_id
 * @property string|null $user_id
 * @property CarbonImmutable|null $expires
 */
class Oauth2AuthorizationCode extends MailcowModel
{
    protected $table = 'oauth_authorization_codes';

    protected $primaryKey = 'authorization_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['authorization_code'];

    protected function casts(): array
    {
        return ['expires' => MailcowDateTime::class];
    }
}
