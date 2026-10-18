<?php

namespace App\Auth;

/**
 * Who verified the password of a password sign-in (feature 012, research R8): mailcow's own hash,
 * the LDAP directory, or the hash Keycloak keeps in the user's `mailcow_password` attribute (the
 * mailpassword flow). It selects the sign-in method of the session.
 */
enum PasswordSource: string
{
    case Mailcow = 'mailcow';
    case Ldap = 'ldap';
    case Keycloak = 'keycloak';

    public function method(bool $withSecondFactor): AuthMethod
    {
        return match ($this) {
            self::Mailcow => $withSecondFactor ? AuthMethod::PasswordAndSecondFactor : AuthMethod::Password,
            self::Ldap => $withSecondFactor ? AuthMethod::DirectoryPasswordAndSecondFactor : AuthMethod::DirectoryPassword,
            self::Keycloak => $withSecondFactor ? AuthMethod::ProviderPasswordAndSecondFactor : AuthMethod::ProviderPassword,
        };
    }

    public function identityProvider(): ?IdentityProviderType
    {
        return match ($this) {
            self::Mailcow => null,
            self::Ldap => IdentityProviderType::Ldap,
            self::Keycloak => IdentityProviderType::Keycloak,
        };
    }
}
