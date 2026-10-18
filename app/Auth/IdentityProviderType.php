<?php

namespace App\Auth;

/**
 * Type of the external identity provider (contract `IdentityProviderType`). mailcow stores it as
 * `keycloak`, `generic-oidc` or `ldap` (`identity_provider.authsource` and `mailbox.authsource`).
 */
enum IdentityProviderType: string
{
    case Keycloak = 'keycloak';
    case GenericOidc = 'genericOidc';
    case Ldap = 'ldap';

    /**
     * The type of a value stored by mailcow, or null for anything else (`mailcow`, empty).
     */
    public static function fromLegacy(?string $value): ?self
    {
        return match ($value) {
            'keycloak' => self::Keycloak,
            'generic-oidc' => self::GenericOidc,
            'ldap' => self::Ldap,
            default => null,
        };
    }

    /**
     * The value mailcow stores.
     */
    public function legacy(): string
    {
        return match ($this) {
            self::Keycloak => 'keycloak',
            self::GenericOidc => 'generic-oidc',
            self::Ldap => 'ldap',
        };
    }

    /** Single sign-on through the provider (Keycloak and generic OIDC). */
    public function offersSingleSignOn(): bool
    {
        return $this !== self::Ldap;
    }

    /** User import and periodic full synchronisation by the legacy jobs (Keycloak and LDAP). */
    public function offersSynchronisation(): bool
    {
        return $this !== self::GenericOidc;
    }
}
