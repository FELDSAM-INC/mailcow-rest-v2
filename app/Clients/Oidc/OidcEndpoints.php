<?php

namespace App\Clients\Oidc;

use App\Auth\IdentityProviderType;
use App\Services\IdentityProvider\IdentityProviderConfig;
use LogicException;

/**
 * The endpoints, scopes and client credentials of an OIDC provider (research R1). Keycloak's lie
 * below `<serverUrl>/realms/<realm>/protocol/openid-connect/` with the scopes `profile email`,
 * plus `openid` from version 20.0.0, as legacy's Keycloak provider library computes them; a
 * generic OIDC provider's are configured. Never logged: it carries the client secret.
 */
final readonly class OidcEndpoints
{
    public function __construct(
        public string $authorization,
        public string $token,
        public string $userInfo,
        public string $scopes,
        public string $clientId = '',
        #[\SensitiveParameter]
        public string $clientSecret = '',
        public bool $ignoreTlsErrors = false,
    ) {}

    public static function forKeycloak(string $serverUrl, string $realm, string $version): self
    {
        $base = rtrim($serverUrl, '/').'/realms/'.$realm.'/protocol/openid-connect';
        $scopes = ['profile', 'email'];

        if (version_compare($version, '20.0.0', '>=')) {
            $scopes[] = 'openid';
        }

        return new self($base.'/auth', $base.'/token', $base.'/userinfo', implode(' ', $scopes));
    }

    public static function forGenericOidc(string $authorization, string $token, string $userInfo, string $scopes): self
    {
        return new self($authorization, $token, $userInfo, $scopes);
    }

    /**
     * The endpoints and credentials of a Keycloak or generic OIDC configuration.
     */
    public static function forConfig(IdentityProviderConfig $config, ?string $secret = null): self
    {
        $endpoints = match ($config->type) {
            IdentityProviderType::Keycloak => self::forKeycloak((string) $config->get('serverUrl'), (string) $config->get('realm'), (string) $config->get('version')),
            IdentityProviderType::GenericOidc => self::forGenericOidc((string) $config->get('authorizationEndpoint'), (string) $config->get('tokenEndpoint'), (string) $config->get('userInfoEndpoint'), (string) $config->get('scopes')),
            IdentityProviderType::Ldap => throw new LogicException('An LDAP configuration has no OIDC endpoints.'),
        };

        return $endpoints->withClient((string) $config->get('clientId'), $secret ?? (string) $config->secret(), (bool) $config->get('ignoreTlsErrors'));
    }

    public function withClient(string $clientId, #[\SensitiveParameter] string $clientSecret, bool $ignoreTlsErrors): self
    {
        return new self($this->authorization, $this->token, $this->userInfo, $this->scopes, $clientId, $clientSecret, $ignoreTlsErrors);
    }
}
