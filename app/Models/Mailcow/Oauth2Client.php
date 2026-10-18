<?php

namespace App\Models\Mailcow;

/**
 * mailcow `oauth_clients`: clients of mailcow's own OAuth2 provider (feature 012), used by the
 * legacy `/oauth/*` endpoints. Read only; app/Services/Oauth2/Oauth2ClientService writes them.
 * Addressed by the numeric `id` (the table's primary key is `client_id`).
 *
 * @property int $id
 * @property string $client_id
 * @property string|null $client_secret
 * @property string|null $redirect_uri
 * @property string|null $grant_types
 * @property string|null $scope
 * @property string|null $user_id
 */
class Oauth2Client extends MailcowModel
{
    protected $table = 'oauth_clients';

    protected $primaryKey = 'id';

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return ['id' => 'integer'];
    }
}
