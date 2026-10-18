<?php

namespace App\Services\Mailboxes;

use Illuminate\Support\Facades\DB;

/**
 * The type of the external identity provider configured in mailcow (`identity_provider` key
 * `authsource`, feature 003 FR-025, FR-064): `keycloak`, `genericOidc` or `ldap` in contract names,
 * or `none`. It decides which authentication sources a mailbox may be given. Read on every call;
 * no secret or connection detail is read.
 */
final class IdentityProviderType
{
    public static function current(): string
    {
        $stored = DB::table('identity_provider')->where('key', 'authsource')->value('value');
        $type = MailboxValues::authSource($stored);

        return is_string($stored) && $type !== 'mailcow' ? $type : 'none';
    }

    /**
     * The authentication sources a mailbox may be given or switched to now.
     *
     * @return list<string>
     */
    public static function allowedSources(): array
    {
        $type = self::current();

        return $type === 'none' ? ['mailcow'] : ['mailcow', $type];
    }
}
