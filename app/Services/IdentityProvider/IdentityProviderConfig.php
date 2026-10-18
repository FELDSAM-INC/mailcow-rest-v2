<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;

/**
 * One identity provider configuration (FR-005 to FR-008, research R9, data-model): its type and its
 * settings in contract member names, normalised as legacy normalises them on save. Secrets
 * (`clientSecret`, `bindPassword`) are held here but never presented.
 *
 * Built from stored rows (IdentityProviderSettingsCodec::fromRows, with legacy's completeness
 * computed from the raw rows), from a whole save or a test (fromInput, omitted members take the
 * defaults of FR-007) or as a merge patch of a stored configuration (withPatch).
 */
final readonly class IdentityProviderConfig
{
    public const DEFAULT_SCOPES = 'openid profile email mailcow_template';

    /** Members of every type. */
    public const COMMON = ['attributeMapping', 'defaultTemplate', 'createMailboxOnSignIn', 'ignoreTlsErrors'];

    /** Members of each type, in presentation order (secrets included). */
    public const MEMBERS = [
        'keycloak' => [...self::COMMON, 'legacyReturnUrl', 'additionalReturnUrls', 'serverUrl', 'realm', 'clientId', 'clientSecret', 'version', 'mailpasswordFlow', 'importUsers', 'periodicSync', 'syncInterval'],
        'genericOidc' => [...self::COMMON, 'legacyReturnUrl', 'additionalReturnUrls', 'authorizationEndpoint', 'tokenEndpoint', 'userInfoEndpoint', 'clientId', 'clientSecret', 'scopes'],
        'ldap' => [...self::COMMON, 'hosts', 'port', 'useSsl', 'useStartTls', 'baseDn', 'usernameField', 'filter', 'attributeField', 'bindDn', 'bindPassword', 'importUsers', 'periodicSync', 'syncInterval'],
    ];

    /** Members legacy requires, non-empty, before it uses a provider (identity_provider('init')). */
    public const REQUIRED_FOR_SIGN_IN = [
        'keycloak' => ['serverUrl', 'realm', 'clientId', 'clientSecret', 'legacyReturnUrl', 'version'],
        'genericOidc' => ['clientId', 'clientSecret', 'legacyReturnUrl', 'authorizationEndpoint', 'tokenEndpoint', 'userInfoEndpoint'],
        'ldap' => ['hosts', 'port', 'baseDn', 'bindDn', 'bindPassword'],
    ];

    /** Values of omitted or missing settings (FR-007); members without a default are null. */
    public const DEFAULTS = [
        'attributeMapping' => [],
        'defaultTemplate' => null,
        'createMailboxOnSignIn' => true,
        'ignoreTlsErrors' => false,
        'additionalReturnUrls' => [],
        'mailpasswordFlow' => false,
        'importUsers' => false,
        'periodicSync' => false,
        'syncInterval' => 15,
        'scopes' => self::DEFAULT_SCOPES,
        'hosts' => [],
        'port' => 389,
        'useSsl' => false,
        'useStartTls' => false,
        'usernameField' => 'mail',
        'filter' => null,
        'attributeField' => null,
    ];

    /** @var list<string> */
    private array $missing;

    /**
     * @param  array<string, mixed>  $settings  every member of the type, normalised
     * @param  list<string>|null  $missing  members legacy considers missing (computed when null)
     */
    public function __construct(
        public IdentityProviderType $type,
        public array $settings,
        ?array $missing = null,
    ) {
        $this->missing = $missing ?? self::missingIn($type, $settings);
    }

    /**
     * A whole configuration of a type from contract members: omitted members take their
     * defaults, unknown members are ignored (the request refuses them before).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(IdentityProviderType $type, array $input): self
    {
        $settings = [];

        foreach (self::MEMBERS[$type->value] as $member) {
            $settings[$member] = array_key_exists($member, $input) ? $input[$member] : (self::DEFAULTS[$member] ?? null);
        }

        return new self($type, self::normalise($settings));
    }

    /**
     * The configuration with a merge patch applied (RFC 7396): sent members replace the stored
     * ones, arrays replace whole lists, `null` resets a member to its default (none for the
     * nullable members). Members of another type and `type` are ignored here.
     *
     * @param  array<string, mixed>  $patch
     */
    public function withPatch(array $patch): self
    {
        $settings = $this->settings;

        foreach (self::MEMBERS[$this->type->value] as $member) {
            if (array_key_exists($member, $patch)) {
                $settings[$member] = $patch[$member] ?? (self::DEFAULTS[$member] ?? null);
            }
        }

        return new self($this->type, self::normalise($settings));
    }

    /**
     * The configuration with the secret replaced (a stored secret kept on save, FR-014).
     */
    public function withSecret(?string $secret): self
    {
        $settings = $this->settings;
        $settings[$this->secretMember()] = $secret === '' ? null : $secret;

        return new self($this->type, $settings);
    }

    public function get(string $member): mixed
    {
        return $this->settings[$member] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /**
     * @return list<string>
     */
    public function missingSettings(): array
    {
        return $this->missing;
    }

    /** `clientSecret` for Keycloak and generic OIDC, `bindPassword` for LDAP. */
    public function secretMember(): string
    {
        return $this->type === IdentityProviderType::Ldap ? 'bindPassword' : 'clientSecret';
    }

    public function secret(): ?string
    {
        $secret = $this->settings[$this->secretMember()] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The member whose value receives the secret (FR-014): the Keycloak server URL, the generic
     * OIDC token endpoint, or the LDAP hosts.
     */
    public function secretAddressMember(): string
    {
        return match ($this->type) {
            IdentityProviderType::Keycloak => 'serverUrl',
            IdentityProviderType::GenericOidc => 'tokenEndpoint',
            IdentityProviderType::Ldap => 'hosts',
        };
    }

    /**
     * The normalised address that receives the secret: the server URL without trailing slashes,
     * the token endpoint as given, the hosts joined as legacy stores them.
     */
    public function secretAddress(): string
    {
        $value = $this->settings[$this->secretAddressMember()] ?? null;

        return is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
    }

    /** The LDAP port used for connections: 636 when SSL is on and the port is 389, as legacy does. */
    public function effectivePort(): int
    {
        $port = (int) ($this->settings['port'] ?? 389);

        return ($this->settings['useSsl'] ?? false) === true && $port === 389 ? 636 : $port;
    }

    /**
     * The mailbox template a mapping value selects: the entry whose attribute value equals it
     * exactly (FR-035), else null.
     */
    public function mappedTemplate(?string $mappingValue): ?string
    {
        if ($mappingValue === null || $mappingValue === '') {
            return null;
        }

        foreach ((array) ($this->settings['attributeMapping'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['attributeValue'] ?? null) === $mappingValue) {
                return (string) $entry['template'];
            }
        }

        return null;
    }

    /**
     * Legacy's normalisation on save (identity_provider('edit')): trailing slashes of the Keycloak
     * URL removed, spaces removed from LDAP hosts, attribute names lower-cased, StartTLS off when
     * SSL is on, empty optional texts as null, duplicate return URLs kept once.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function normalise(array $settings): array
    {
        if (array_key_exists('serverUrl', $settings) && is_string($settings['serverUrl'])) {
            $settings['serverUrl'] = rtrim($settings['serverUrl'], '/');
        }

        if (array_key_exists('hosts', $settings)) {
            $settings['hosts'] = array_values(array_filter(
                array_map(fn (mixed $h): string => str_replace(' ', '', (string) $h), (array) $settings['hosts']),
                fn (string $h): bool => $h !== '',
            ));
        }

        foreach (['usernameField', 'attributeField'] as $member) {
            if (array_key_exists($member, $settings) && is_string($settings[$member])) {
                $settings[$member] = strtolower($settings[$member]);
            }
        }

        if (array_key_exists('usernameField', $settings) && in_array($settings['usernameField'], [null, ''], true)) {
            $settings['usernameField'] = 'mail';
        }

        foreach (['filter', 'attributeField', 'defaultTemplate'] as $member) {
            if (array_key_exists($member, $settings) && $settings[$member] === '') {
                $settings[$member] = null;
            }
        }

        if (array_key_exists('scopes', $settings) && in_array($settings['scopes'], [null, ''], true)) {
            $settings['scopes'] = self::DEFAULT_SCOPES;
        }

        if (($settings['useSsl'] ?? false) === true && array_key_exists('useStartTls', $settings)) {
            $settings['useStartTls'] = false;
        }

        if (array_key_exists('additionalReturnUrls', $settings)) {
            $settings['additionalReturnUrls'] = array_values(array_unique(array_map('strval', (array) $settings['additionalReturnUrls'])));
        }

        if (array_key_exists('attributeMapping', $settings)) {
            $settings['attributeMapping'] = array_values(array_map(
                fn (mixed $e): array => ['attributeValue' => (string) ($e['attributeValue'] ?? ''), 'template' => (string) ($e['template'] ?? '')],
                array_filter((array) $settings['attributeMapping'], 'is_array'),
            ));
        }

        foreach (['clientSecret', 'bindPassword'] as $member) {
            if (array_key_exists($member, $settings) && $settings[$member] === '') {
                $settings[$member] = null;
            }
        }

        return $settings;
    }

    /**
     * Members that are empty in legacy's sense (identity_provider('init')).
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private static function missingIn(IdentityProviderType $type, array $settings): array
    {
        $missing = [];

        foreach (self::REQUIRED_FOR_SIGN_IN[$type->value] as $member) {
            $value = $settings[$member] ?? null;

            if ($value === null || $value === '' || $value === '0' || $value === [] || $value === 0) {
                $missing[] = $member;
            }
        }

        return $missing;
    }
}
