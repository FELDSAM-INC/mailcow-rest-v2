<?php

namespace App\Clients\Ldap;

use App\Services\IdentityProvider\IdentityProviderConfig;

/**
 * How to reach and search the directory (research R2): the hosts (tried in order), the port, SSL
 * or StartTLS, whether certificate errors are ignored, the base DN, the service account and the
 * search settings. Never logged: it carries the bind password.
 */
final readonly class LdapConnectionSettings
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public array $hosts,
        public int $port,
        public bool $useSsl,
        public bool $useStartTls,
        public bool $ignoreTlsErrors,
        public string $baseDn,
        public string $bindDn,
        #[\SensitiveParameter]
        public string $bindPassword,
        public string $usernameField = 'mail',
        public ?string $filter = null,
        public ?string $attributeField = null,
    ) {}

    public static function fromConfig(IdentityProviderConfig $config, #[\SensitiveParameter] ?string $bindPassword = null): self
    {
        return new self(
            hosts: array_values(array_map('strval', (array) $config->get('hosts'))),
            port: (int) $config->get('port'),
            useSsl: (bool) $config->get('useSsl'),
            useStartTls: (bool) $config->get('useStartTls') && ! $config->get('useSsl'),
            ignoreTlsErrors: (bool) $config->get('ignoreTlsErrors'),
            baseDn: (string) $config->get('baseDn'),
            bindDn: (string) $config->get('bindDn'),
            bindPassword: $bindPassword ?? (string) $config->secret(),
            usernameField: (string) ($config->get('usernameField') ?? 'mail'),
            filter: is_string($config->get('filter')) ? $config->get('filter') : null,
            attributeField: is_string($config->get('attributeField')) ? $config->get('attributeField') : null,
        );
    }

    /** The connection URIs, separated by spaces (SSL on 389 uses 636, as legacy does). */
    public function uris(): string
    {
        return ExtLdapDirectory::uris($this->hosts, $this->port, $this->useSsl);
    }
}
