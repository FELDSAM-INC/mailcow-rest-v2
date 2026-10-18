<?php

namespace App\Models\Mailcow;

use App\Casts\MailcowDateTime;
use Carbon\CarbonImmutable;

/**
 * mailcow `oauth_access_tokens`, issued by the legacy `/oauth/*` endpoints (feature 012). Read only;
 * App\Services\Oauth2\Oauth2ClientService counts and removes them per client. `expires` is a
 * TIMESTAMP in mailcow's time zone.
 *
 * @property string $access_token
 * @property string $client_id
 * @property string|null $user_id
 * @property CarbonImmutable|null $expires
 */
class Oauth2AccessToken extends MailcowModel
{
    protected $table = 'oauth_access_tokens';

    protected $primaryKey = 'access_token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return ['expires' => MailcowDateTime::class];
    }
}
