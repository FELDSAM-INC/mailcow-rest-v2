<?php

namespace App\Services\IdentityProvider;

use App\Clients\Keycloak\KeycloakAdminClient;
use App\Clients\Oidc\OidcFailure;
use Illuminate\Support\Facades\DB;

/**
 * The Keycloak service token of the mailcow client (research R3), a port of legacy
 * identity_provider('get-keycloak-admin-token'): the token cached in the `identity_provider` row
 * `access_token` is reused while Keycloak reports it active, otherwise a new one is requested with
 * the client credentials grant and stored in that row, which legacy, its synchronisation job and
 * this API share. The row is not part of the configuration's version tag. This class owns the
 * row's writes outside a configuration save.
 */
class KeycloakServiceTokenCache
{
    public function __construct(private readonly KeycloakAdminClient $keycloak) {}

    /**
     * @throws OidcFailure when Keycloak cannot be reached or refuses the service account
     */
    public function token(IdentityProviderConfig $config): string
    {
        $cached = DB::table('identity_provider')->where('key', IdentityProviderSettingsCodec::ACCESS_TOKEN)->value('value');

        if (is_string($cached) && $cached !== '' && $this->keycloak->introspect($config, $cached)) {
            return $cached;
        }

        try {
            $token = $this->keycloak->serviceToken($config);
        } catch (OidcFailure $e) {
            // Any refusal of the service account is an unavailable provider for sign-ins (FR-033).
            throw OidcFailure::unavailable($e->status, $e->curlErrno);
        }

        DB::statement('INSERT INTO `identity_provider` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [IdentityProviderSettingsCodec::ACCESS_TOKEN, $token]);

        return $token;
    }

    /**
     * Forgets the cached token (Keycloak answered 401 with it).
     */
    public function invalidate(): void
    {
        DB::table('identity_provider')->where('key', IdentityProviderSettingsCodec::ACCESS_TOKEN)->update(['value' => '']);
    }
}
