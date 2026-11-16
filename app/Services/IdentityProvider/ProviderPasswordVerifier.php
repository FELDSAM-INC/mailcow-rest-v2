<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use App\Clients\Keycloak\KeycloakAdminClient;
use App\Clients\Ldap\LdapConnectionSettings;
use App\Clients\Ldap\LdapDirectory;
use App\Clients\Ldap\LdapResult;
use App\Clients\Ldap\LdapStatus;
use App\Clients\Oidc\OidcFailure;
use App\Models\Mailcow\SchemaVersion;
use App\Support\Hashing\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;

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
        $idAttributes = [];
        if (SchemaVersion::hasNovember2026Features()) {
            $configured = DB::table('identity_provider')->where('key', 'id_attribute')->value('value');
            $idAttributes = is_string($configured) && $configured !== '' ? [strtolower($configured)] : ['objectguid', 'entryuuid', 'nsuniqueid', 'ipauniqueid'];
            $attributes = array_values(array_unique([...$attributes, ...$idAttributes]));
        }
        $result = $this->ldap->findAndBind($settings, $username, $password, $attributes);

        return match ($result->status) {
            LdapStatus::Ok => ProviderVerification::verified(
                $settings->attributeField === null ? null : $result->first($settings->attributeField),
                $result->first('displayname'),
                self::ldapIdentity($result, $idAttributes),
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

        return ProviderVerification::verified(is_string($template) && $template !== '' ? $template : null, $name === '' ? null : $name,
            is_string($user['id'] ?? null) ? $user['id'] : null);
    }

    /** @param list<string> $attributes */
    private static function ldapIdentity(LdapResult $result, array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $value = $result->first($attribute);
            if ($value === null || $value === '') {
                continue;
            }
            if ($attribute === 'objectguid' && strlen($value) === 16) {
                $hex = bin2hex($value);

                return implode('', array_reverse(str_split(substr($hex, 0, 8), 2))).'-'
                    .implode('', array_reverse(str_split(substr($hex, 8, 4), 2))).'-'
                    .implode('', array_reverse(str_split(substr($hex, 12, 4), 2))).'-'
                    .substr($hex, 16, 4).'-'.substr($hex, 20);
            }

            return strtolower($value);
        }

        return $result->dn === null || $result->dn === '' ? null : 'dn:'.strtolower($result->dn);
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
