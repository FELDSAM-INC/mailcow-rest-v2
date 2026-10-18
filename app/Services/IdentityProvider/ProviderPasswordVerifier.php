<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use App\Clients\Keycloak\KeycloakAdminClient;
use App\Clients\Ldap\LdapConnectionSettings;
use App\Clients\Ldap\LdapDirectory;
use App\Clients\Ldap\LdapStatus;
use App\Clients\Oidc\OidcFailure;
use App\Support\Hashing\LegacyPasswordVerifier;

/**
 * Verifies a mailbox user's password at the identity provider (FR-029, research R2, R3), ports of
 * legacy ldap_mbox_login() and keycloak_mbox_login_rest():
 *
 *  - LDAP: the entry whose username field equals the username, below the base DN and within the
 *    filter, found with the service account; the password is verified by a bind as that entry;
 *  - Keycloak (mailpassword flow): the user with exactly this e-mail address, looked up with the
 *    service token (a 401 is retried once with a new token); the password is verified against the
 *    hash in the user's `mailcow_password` attribute with every hash format of feature 001.
 *
 * Every call is made before any database transaction opens (research R8).
 */
class ProviderPasswordVerifier
{
    public function __construct(
        private readonly LdapDirectory $ldap,
        private readonly KeycloakAdminClient $keycloak,
        private readonly KeycloakServiceTokenCache $tokens,
        private readonly LegacyPasswordVerifier $passwords,
    ) {}

    /**
     * Whether the configuration can verify passwords of mailboxes of its type: complete, and LDAP
     * or Keycloak with the mailpassword flow.
     */
    public static function canVerify(?IdentityProviderConfig $config): bool
    {
        return $config !== null && $config->isComplete() && match ($config->type) {
            IdentityProviderType::Ldap => true,
            IdentityProviderType::Keycloak => (bool) $config->get('mailpasswordFlow'),
            IdentityProviderType::GenericOidc => false,
        };
    }

    public function verify(IdentityProviderConfig $config, string $username, #[\SensitiveParameter] string $password): ProviderVerification
    {
        return $config->type === IdentityProviderType::Ldap
            ? $this->verifyLdap($config, $username, $password)
            : $this->verifyKeycloak($config, $username, $password);
    }

    private function verifyLdap(IdentityProviderConfig $config, string $username, #[\SensitiveParameter] string $password): ProviderVerification
    {
        $settings = LdapConnectionSettings::fromConfig($config);
        $attributes = array_values(array_filter([$settings->usernameField, $settings->attributeField, 'displayname', 'distinguishedname', 'dn'], fn (?string $a): bool => $a !== null && $a !== ''));
        $result = $this->ldap->findAndBind($settings, $username, $password, $attributes);

        return match ($result->status) {
            LdapStatus::Ok => ProviderVerification::verified(
                $settings->attributeField === null ? null : $result->first($settings->attributeField),
                $result->first('displayname'),
            ),
            LdapStatus::WrongPassword => ProviderVerification::wrongPassword(),
            LdapStatus::UnknownUser => ProviderVerification::unknownUser(),
            default => ProviderVerification::unavailable(),
        };
    }

    private function verifyKeycloak(IdentityProviderConfig $config, string $username, #[\SensitiveParameter] string $password): ProviderVerification
    {
        try {
            $users = $this->users($config, $username);
        } catch (OidcFailure) {
            return ProviderVerification::unavailable();
        }

        $user = $users[0] ?? null;

        if (! is_array($user)) {
            return ProviderVerification::unknownUser();
        }

        $attributes = is_array($user['attributes'] ?? null) ? $user['attributes'] : [];
        $hash = $attributes['mailcow_password'][0] ?? null;

        if (! is_string($hash) || $hash === '') {
            return ProviderVerification::notVerifiable();
        }

        if (! $this->passwords->verify($hash, $password)) {
            return ProviderVerification::wrongPassword();
        }

        $template = $attributes['mailcow_template'][0] ?? null;
        // Deviation (FR-037): legacy reads `name`, which Keycloak's admin API never provides.
        $name = trim((is_string($user['firstName'] ?? null) ? $user['firstName'] : '').' '.(is_string($user['lastName'] ?? null) ? $user['lastName'] : ''));

        return ProviderVerification::verified(is_string($template) && $template !== '' ? $template : null, $name === '' ? null : $name);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws OidcFailure
     */
    private function users(IdentityProviderConfig $config, string $username): array
    {
        try {
            return $this->keycloak->findUsersByEmail($config, $this->tokens->token($config), $username);
        } catch (OidcFailure $e) {
            if ($e->status !== 401) {
                throw $e;
            }

            // The cached token expired between introspection and use.
            $this->tokens->invalidate();

            return $this->keycloak->findUsersByEmail($config, $this->tokens->token($config), $username);
        }
    }
}
