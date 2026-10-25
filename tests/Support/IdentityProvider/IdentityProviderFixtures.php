<?php

namespace Tests\Support\IdentityProvider;

/**
 * Standard request bodies and provider payloads of feature 012's tests (T009). The secrets are the
 * distinctive values the secret scans look for.
 */
final class IdentityProviderFixtures
{
    public const LDAP_BIND_PASSWORD = 'ldap-secret';

    public const KEYCLOAK_SECRET = 'kc-secret';

    public const OIDC_SECRET = 'oidc-secret';

    /**
     * A whole LDAP configuration (`IdentityProviderLdapInput`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function ldap(array $overrides = []): array
    {
        return array_merge([
            'type' => 'ldap',
            'hosts' => ['ldap1.example.org', 'ldap2.example.org'],
            'port' => 389,
            'baseDn' => 'ou=people,dc=example,dc=org',
            'filter' => '(objectClass=inetOrgPerson)',
            'attributeField' => 'title',
            'bindDn' => 'cn=admin,dc=example,dc=org',
            'bindPassword' => self::LDAP_BIND_PASSWORD,
            'attributeMapping' => [['attributeValue' => 'staff', 'template' => 'Staff']],
        ], $overrides);
    }

    /**
     * A whole Keycloak configuration (`IdentityProviderKeycloakInput`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function keycloak(array $overrides = []): array
    {
        return array_merge([
            'type' => 'keycloak',
            'serverUrl' => 'https://sso.example.org',
            'realm' => 'example',
            'clientId' => 'mailcow',
            'clientSecret' => self::KEYCLOAK_SECRET,
            'version' => '26.0.7',
            'legacyReturnUrl' => 'https://mail.example.org',
            'attributeMapping' => [['attributeValue' => 'staff', 'template' => 'Staff']],
        ], $overrides);
    }

    /**
     * A whole generic OIDC configuration (`IdentityProviderGenericOidcInput`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function genericOidc(array $overrides = []): array
    {
        return array_merge([
            'type' => 'genericOidc',
            'authorizationEndpoint' => 'https://idp.example.net/oauth2/authorize',
            'tokenEndpoint' => 'https://idp.example.net/oauth2/token',
            'userInfoEndpoint' => 'https://idp.example.net/oauth2/userinfo',
            'clientId' => 'mailcow',
            'clientSecret' => self::OIDC_SECRET,
            'legacyReturnUrl' => 'https://mail.example.org',
        ], $overrides);
    }

    /**
     * User details of the user info endpoint.
     *
     * @param  string|list<string>|null  $template  the `mailcow_template` value
     * @return array<string, mixed>
     */
    public static function userInfo(string $email, ?string $name = null, string|array|null $template = null): array
    {
        $claims = ['sub' => 'sub-'.sha1($email), 'email' => $email, 'email_verified' => true, 'preferred_username' => explode('@', $email)[0]];

        if ($name !== null) {
            $claims['name'] = $name;
        }

        if ($template !== null) {
            $claims['mailcow_template'] = $template;
        }

        return $claims;
    }

    /**
     * A user of Keycloak's admin API, as the mailpassword flow reads it.
     *
     * @return array<string, mixed>
     */
    public static function keycloakUser(string $email, ?string $passwordHash, ?string $template = null, string $firstName = 'Anna', string $lastName = 'Example'): array
    {
        $attributes = [];

        if ($passwordHash !== null) {
            $attributes['mailcow_password'] = [$passwordHash];
        }

        if ($template !== null) {
            $attributes['mailcow_template'] = [$template];
        }

        return [
            'id' => 'kc-'.sha1($email),
            'username' => $email,
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'enabled' => true,
            'attributes' => $attributes,
        ];
    }
}
